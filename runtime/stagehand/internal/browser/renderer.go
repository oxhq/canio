package browser

import (
	"context"
	"encoding/json"
	"fmt"
	"html"
	"strings"
	"sync"
	"time"

	"github.com/chromedp/cdproto/cdp"
	"github.com/chromedp/cdproto/emulation"
	"github.com/chromedp/cdproto/network"
	"github.com/chromedp/cdproto/page"
	cdpruntime "github.com/chromedp/cdproto/runtime"
	"github.com/chromedp/chromedp"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

type PoolStatus struct {
	Size       int
	Warm       int
	Busy       int
	Starting   int
	Waiting    int
	QueueLimit int
}

type Renderer struct {
	config  config.RuntimeConfig
	once    sync.Once
	initErr error
	pool    *Pool
	mu      sync.RWMutex
}

func New(cfg config.RuntimeConfig) *Renderer {
	return &Renderer{
		config: cfg,
	}
}

func (r *Renderer) Render(ctx context.Context, spec contracts.RenderSpec) ([]byte, []string, *contracts.DebugArtifacts, error) {
	renderCtx, renderCancel := context.WithTimeout(ctx, resolveRenderTimeout(spec))
	defer renderCancel()

	if err := r.ensureStarted(renderCtx); err != nil {
		return nil, nil, nil, err
	}

	lease, err := r.acquireLease(renderCtx, spec)
	if err != nil {
		return nil, nil, nil, err
	}
	defer func() {
		_ = lease.Release()
	}()

	tabCtx, tabCancel := chromedp.NewContext(lease.Browser().Context())
	defer tabCancel()

	runCtx, runCancel := bindContext(renderCtx, tabCtx)
	defer runCancel()

	var artifactsCollector *debugArtifactsCollector
	if contracts.DebugArtifactsEnabled(spec) {
		artifactsCollector = newDebugArtifactsCollector(runCtx)
	}

	if err := chromedp.Run(runCtx,
		network.Enable(),
		page.Enable(),
		cdpruntime.Enable(),
		emulation.SetEmulatedMedia().WithMedia("print"),
	); err != nil {
		return nil, nil, nil, err
	}

	warnings, err := r.prepareDocument(runCtx, spec)
	if err != nil {
		return nil, warnings, nil, err
	}

	if err := r.waitUntilReady(runCtx, spec); err != nil {
		return nil, warnings, nil, err
	}

	pdfData, err := r.printToPDF(runCtx, spec)
	if err != nil {
		return nil, warnings, nil, err
	}

	var debugArtifacts *contracts.DebugArtifacts
	if artifactsCollector != nil {
		var artifactWarnings []string
		debugArtifacts, artifactWarnings = artifactsCollector.Finalize(runCtx)
		warnings = append(warnings, artifactWarnings...)
	}

	return pdfData, warnings, debugArtifacts, nil
}

func (r *Renderer) Status() PoolStatus {
	r.mu.RLock()
	pool := r.pool
	size := r.config.BrowserPoolSize
	queueLimit := r.config.BrowserQueueDepth
	defer r.mu.RUnlock()

	if size <= 0 {
		size = 1
	}

	if queueLimit < 0 {
		queueLimit = 0
	}

	if pool == nil {
		return PoolStatus{
			Size:       size,
			QueueLimit: queueLimit,
		}
	}

	stats := pool.Stats()

	return PoolStatus{
		Size:       stats.MaxBrowsers,
		Warm:       stats.ReadyBrowsers,
		Busy:       stats.BusyBrowsers,
		Starting:   stats.StartingBrowsers,
		Waiting:    stats.Waiting,
		QueueLimit: stats.QueueLimit,
	}
}

func (r *Renderer) Close() {
	r.mu.Lock()
	pool := r.pool
	r.pool = nil
	r.mu.Unlock()

	if pool != nil {
		_ = pool.Close()
	}
}

func (r *Renderer) ensureStarted(ctx context.Context) error {
	r.once.Do(func() {
		pool, err := NewPool(processFactory{config: r.config}, Options{
			MaxBrowsers: normalizePoolSize(r.config.BrowserPoolSize),
			QueueDepth:  normalizeQueueDepth(r.config.BrowserQueueDepth),
		})
		if err != nil {
			r.initErr = err
			return
		}

		if warmCount := normalizeWarmCount(r.config.BrowserPoolWarm, r.config.BrowserPoolSize); warmCount > 0 {
			if err := pool.Warm(ctx, warmCount); err != nil {
				_ = pool.Close()
				r.initErr = err
				return
			}
		}

		r.mu.Lock()
		r.pool = pool
		r.mu.Unlock()
	})

	return r.initErr
}

func (r *Renderer) acquireLease(ctx context.Context, spec contracts.RenderSpec) (*Lease, error) {
	r.mu.RLock()
	pool := r.pool
	r.mu.RUnlock()

	if pool == nil {
		return nil, ErrPoolClosed
	}

	timeout := resolveAcquireTimeout(spec, r.config)
	if timeout <= 0 {
		return pool.Acquire(ctx)
	}

	return pool.AcquireWithTimeout(ctx, timeout)
}

func (r *Renderer) prepareDocument(ctx context.Context, spec contracts.RenderSpec) ([]string, error) {
	switch spec.Source.Type {
	case "html":
		return r.prepareHTML(ctx, spec)
	case "view":
		if htmlMarkup := resolveString(spec.Source.Payload["html"]); htmlMarkup != "" {
			spec.Source.Type = "html"
			spec.Source.Payload = map[string]any{
				"html": htmlMarkup,
			}
			return r.prepareHTML(ctx, spec)
		}

		return nil, fmt.Errorf("source.type=view requires normalized payload.html before it reaches Stagehand")
	case "url":
		return nil, r.prepareURL(ctx, spec)
	default:
		return nil, fmt.Errorf("unsupported render source %q", spec.Source.Type)
	}
}

func (r *Renderer) prepareHTML(ctx context.Context, spec contracts.RenderSpec) ([]string, error) {
	htmlMarkup := resolveString(spec.Source.Payload["html"])
	if strings.TrimSpace(htmlMarkup) == "" {
		return nil, fmt.Errorf("html render source is missing payload.html")
	}

	baseURL := resolveString(spec.Source.Payload["baseUrl"])
	targetURL := "about:blank"
	warnings := make([]string, 0, 1)

	if baseURL != "" {
		targetURL = baseURL
	}

	if err := chromedp.Run(ctx, chromedp.Navigate(targetURL)); err != nil {
		if baseURL == "" {
			return warnings, err
		}

		warnings = append(warnings, fmt.Sprintf("base URL %q was unreachable; Stagehand fell back to about:blank", baseURL))
		if fallbackErr := chromedp.Run(ctx, chromedp.Navigate("about:blank")); fallbackErr != nil {
			return warnings, fallbackErr
		}
	}

	if baseURL != "" {
		htmlMarkup = injectBaseHref(htmlMarkup, baseURL)
	}

	var frameID cdp.FrameID
	if err := chromedp.Run(ctx,
		page.SetBypassCSP(true),
		chromedp.ActionFunc(func(ctx context.Context) error {
			frameTree, err := page.GetFrameTree().Do(ctx)
			if err != nil {
				return err
			}

			frameID = frameTree.Frame.ID
			return nil
		}),
		chromedp.ActionFunc(func(ctx context.Context) error {
			return page.SetDocumentContent(frameID, htmlMarkup).Do(ctx)
		}),
	); err != nil {
		return warnings, err
	}

	if title := strings.TrimSpace(spec.Document.Title); title != "" {
		if err := setDocumentTitle(ctx, title); err != nil {
			return warnings, err
		}
	}

	return warnings, nil
}

func (r *Renderer) prepareURL(ctx context.Context, spec contracts.RenderSpec) error {
	targetURL := resolveString(spec.Source.Payload["url"])
	if strings.TrimSpace(targetURL) == "" {
		return fmt.Errorf("url render source is missing payload.url")
	}

	resp, err := chromedp.RunResponse(ctx, chromedp.Navigate(targetURL))
	if err != nil {
		return err
	}

	if resp != nil && resp.Status >= 400 {
		return fmt.Errorf("navigation to %s returned HTTP %d", targetURL, resp.Status)
	}

	if title := strings.TrimSpace(spec.Document.Title); title != "" {
		return setDocumentTitle(ctx, title)
	}

	return nil
}

func (r *Renderer) waitUntilReady(ctx context.Context, spec contracts.RenderSpec) error {
	waitTimeout := resolveWaitTimeout(spec)

	return chromedp.Run(ctx,
		chromedp.WaitReady("body", chromedp.ByQuery),
		chromedp.PollFunction(`() => {
			const documentReady = document.readyState === "complete";
			const fontsReady = !document.fonts || document.fonts.status === "loaded";
			const imagesReady = Array.from(document.images || []).every((img) => img.complete);
			const canioReady = typeof window.__CANIO_READY__ === "undefined" || window.__CANIO_READY__ === true;
			const legacyReady = typeof window.CANIO_READY === "undefined" || window.CANIO_READY === true;
			return documentReady && fontsReady && imagesReady && canioReady && legacyReady;
		}`, nil,
			chromedp.WithPollingInterval(100*time.Millisecond),
			chromedp.WithPollingTimeout(waitTimeout),
		),
		chromedp.Sleep(150*time.Millisecond),
	)
}

func (r *Renderer) printToPDF(ctx context.Context, spec contracts.RenderSpec) ([]byte, error) {
	presentation := spec.Presentation
	params := page.PrintToPDF()

	params = params.WithPrintBackground(resolveBool(presentation["background"], true))
	params = params.WithLandscape(resolveBool(presentation["landscape"], false))
	params = params.WithGenerateTaggedPDF(spec.Document.Tagged)

	if scale, ok := resolveFloat(presentation["scale"]); ok && scale > 0 {
		params = params.WithScale(scale)
	}

	if pageRanges := resolveString(presentation["pageRanges"]); pageRanges != "" {
		params = params.WithPageRanges(pageRanges)
	}

	if width, height, ok := resolvePaperSize(presentation); ok {
		params = params.WithPaperWidth(width).WithPaperHeight(height)
	}

	if margins, ok := resolveMargins(presentation["margins"]); ok {
		params = params.
			WithMarginTop(margins[0]).
			WithMarginRight(margins[1]).
			WithMarginBottom(margins[2]).
			WithMarginLeft(margins[3])
	}

	if headerTemplate, footerTemplate, display := resolveHeaderAndFooter(spec); display {
		params = params.WithDisplayHeaderFooter(true)

		if headerTemplate != "" {
			params = params.WithHeaderTemplate(headerTemplate)
		}

		if footerTemplate != "" {
			params = params.WithFooterTemplate(footerTemplate)
		}
	}

	var data []byte
	err := chromedp.Run(ctx, chromedp.ActionFunc(func(ctx context.Context) error {
		var err error
		data, _, err = params.Do(ctx)
		return err
	}))

	return data, err
}

func bindContext(parent context.Context, base context.Context) (context.Context, context.CancelFunc) {
	var (
		ctx    context.Context
		cancel context.CancelFunc
	)

	if deadline, ok := parent.Deadline(); ok {
		ctx, cancel = context.WithDeadline(base, deadline)
	} else {
		ctx, cancel = context.WithCancel(base)
	}

	stopped := make(chan struct{})
	go func() {
		select {
		case <-parent.Done():
			cancel()
		case <-ctx.Done():
		case <-stopped:
		}
	}()

	return ctx, func() {
		close(stopped)
		cancel()
	}
}

func resolveRenderTimeout(spec contracts.RenderSpec) time.Duration {
	timeoutSeconds := 30

	if executionTimeout, ok := resolveInt(spec.Execution["timeout"]); ok && executionTimeout > 0 {
		timeoutSeconds = executionTimeout
	}

	return time.Duration(timeoutSeconds) * time.Second
}

func resolveWaitTimeout(spec contracts.RenderSpec) time.Duration {
	if waitConfig, ok := spec.Execution["wait"].(map[string]any); ok {
		if timeout, ok := resolveInt(waitConfig["timeout"]); ok && timeout > 0 {
			return time.Duration(timeout) * time.Second
		}
	}

	return resolveRenderTimeout(spec)
}

func resolveAcquireTimeout(spec contracts.RenderSpec, cfg config.RuntimeConfig) time.Duration {
	timeoutSeconds := cfg.AcquireTimeoutSec

	if queueTimeout, ok := resolveInt(spec.Queue["timeout"]); ok && queueTimeout > 0 {
		timeoutSeconds = queueTimeout
	}

	if timeoutSeconds <= 0 {
		return 0
	}

	return time.Duration(timeoutSeconds) * time.Second
}

func normalizePoolSize(size int) int {
	if size <= 0 {
		return 1
	}

	return size
}

func normalizeWarmCount(warmCount int, poolSize int) int {
	if warmCount <= 0 {
		return 0
	}

	size := normalizePoolSize(poolSize)
	if warmCount > size {
		return size
	}

	return warmCount
}

func normalizeQueueDepth(depth int) int {
	if depth < 0 {
		return 0
	}

	return depth
}

func resolvePaperSize(presentation map[string]any) (float64, float64, bool) {
	if raw, ok := presentation["paperSize"]; ok {
		if paper, ok := raw.([]any); ok && len(paper) >= 3 {
			width, widthOK := resolveFloat(paper[0])
			height, heightOK := resolveFloat(paper[1])
			unit := resolveString(paper[2])
			if widthOK && heightOK {
				return toInches(width, unit), toInches(height, unit), true
			}
		}
	}

	format := strings.ToLower(strings.TrimSpace(resolveString(presentation["format"])))

	switch format {
	case "a4":
		return 8.2677, 11.6929, true
	case "letter":
		return 8.5, 11.0, true
	case "legal":
		return 8.5, 14.0, true
	case "a3":
		return 11.6929, 16.5354, true
	default:
		return 0, 0, false
	}
}

func resolveMargins(raw any) ([4]float64, bool) {
	var values [4]float64

	parts, ok := raw.([]any)
	if !ok || len(parts) < 4 {
		return values, false
	}

	for index := range 4 {
		value, ok := resolveFloat(parts[index])
		if !ok {
			return values, false
		}

		values[index] = toInches(value, "mm")
	}

	return values, true
}

func resolveHeaderAndFooter(spec contracts.RenderSpec) (string, string, bool) {
	headerTemplate := resolveString(spec.Presentation["headerHtml"])
	footerTemplate := resolveString(spec.Presentation["footerHtml"])
	pageNumbers := resolveBool(spec.Presentation["pageNumbers"], false)

	if pageNumbers && footerTemplate == "" {
		footerTemplate = defaultPageNumberFooter()
	}

	display := headerTemplate != "" || footerTemplate != "" || pageNumbers

	return headerTemplate, footerTemplate, display
}

func defaultPageNumberFooter() string {
	return `<div style="width:100%;font-size:8px;color:#666;padding:0 12px;text-align:center;"><span class="pageNumber"></span> / <span class="totalPages"></span></div>`
}

func injectBaseHref(markup string, baseURL string) string {
	if strings.Contains(strings.ToLower(markup), "<base ") {
		return markup
	}

	baseTag := `<base href="` + html.EscapeString(baseURL) + `">`
	lower := strings.ToLower(markup)

	if idx := strings.Index(lower, "<head>"); idx >= 0 {
		return markup[:idx+len("<head>")] + baseTag + markup[idx+len("<head>"):]
	}

	if idx := strings.Index(lower, "<html"); idx >= 0 {
		if end := strings.Index(lower[idx:], ">"); end >= 0 {
			end += idx
			return markup[:end+1] + "<head>" + baseTag + "</head>" + markup[end+1:]
		}
	}

	return "<head>" + baseTag + "</head>" + markup
}

func setDocumentTitle(ctx context.Context, title string) error {
	encoded, err := json.Marshal(title)
	if err != nil {
		return err
	}

	return chromedp.Run(ctx, chromedp.Evaluate("document.title = "+string(encoded), nil))
}

func resolveBool(raw any, fallback bool) bool {
	switch value := raw.(type) {
	case bool:
		return value
	case string:
		switch strings.ToLower(strings.TrimSpace(value)) {
		case "1", "true", "yes", "on":
			return true
		case "0", "false", "no", "off":
			return false
		default:
			return fallback
		}
	default:
		return fallback
	}
}

func resolveInt(raw any) (int, bool) {
	switch value := raw.(type) {
	case int:
		return value, true
	case int64:
		return int(value), true
	case float64:
		return int(value), true
	case float32:
		return int(value), true
	default:
		return 0, false
	}
}

func resolveFloat(raw any) (float64, bool) {
	switch value := raw.(type) {
	case float64:
		return value, true
	case float32:
		return float64(value), true
	case int:
		return float64(value), true
	case int64:
		return float64(value), true
	case int32:
		return float64(value), true
	default:
		return 0, false
	}
}

func resolveString(raw any) string {
	value, _ := raw.(string)
	return strings.TrimSpace(value)
}

func toInches(value float64, unit string) float64 {
	switch strings.ToLower(strings.TrimSpace(unit)) {
	case "in", "inch", "inches":
		return value
	case "cm":
		return value / 2.54
	case "px":
		return value / 96.0
	case "mm", "":
		fallthrough
	default:
		return value / 25.4
	}
}
