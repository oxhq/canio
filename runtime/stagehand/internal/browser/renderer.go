package browser

import (
	"context"
	"encoding/json"
	"fmt"
	"html"
	"net"
	"net/url"
	"strings"
	"sync"
	"time"

	"github.com/chromedp/cdproto/cdp"
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
	pageMu  sync.RWMutex
	pages   map[int]string
}

func New(cfg config.RuntimeConfig) *Renderer {
	return &Renderer{
		config: cfg,
		pages:  map[int]string{},
	}
}

func (r *Renderer) Render(ctx context.Context, spec contracts.RenderSpec) ([]byte, []string, *contracts.DebugArtifacts, map[string]int64, error) {
	renderCtx, renderCancel := context.WithTimeout(ctx, resolveRenderTimeout(spec))
	defer renderCancel()

	timings := map[string]int64{}
	renderStart := time.Now()

	startedAt := time.Now()
	if err := r.ensureStarted(renderCtx); err != nil {
		return nil, nil, nil, timings, err
	}
	timings["startupMs"] = time.Since(startedAt).Milliseconds()

	startedAt = time.Now()
	lease, err := r.acquireLease(renderCtx, spec)
	if err != nil {
		return nil, nil, nil, timings, err
	}
	defer func() {
		_ = lease.Release()
	}()
	timings["acquireMs"] = time.Since(startedAt).Milliseconds()

	runCtx, runCancel := bindContext(renderCtx, lease.Browser().Context())
	defer runCancel()

	var artifactsCollector *debugArtifactsCollector
	if contracts.DebugArtifactsEnabled(spec) {
		artifactsCollector = newDebugArtifactsCollector(runCtx)
	}

	startedAt = time.Now()
	warnings, err := r.prepareDocument(runCtx, spec, lease.ID())
	if err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["prepareMs"] = time.Since(startedAt).Milliseconds()

	startedAt = time.Now()
	if err := r.waitUntilReady(runCtx, spec); err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["waitMs"] = time.Since(startedAt).Milliseconds()

	startedAt = time.Now()
	pdfData, err := r.printToPDF(runCtx, spec)
	if err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["printMs"] = time.Since(startedAt).Milliseconds()

	var debugArtifacts *contracts.DebugArtifacts
	if artifactsCollector != nil {
		var artifactWarnings []string
		startedAt = time.Now()
		debugArtifacts, artifactWarnings = artifactsCollector.Finalize(runCtx)
		timings["debugArtifactsMs"] = time.Since(startedAt).Milliseconds()
		warnings = append(warnings, artifactWarnings...)
	}

	timings["renderMs"] = time.Since(renderStart).Milliseconds()

	return pdfData, warnings, debugArtifacts, timings, nil
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

func (r *Renderer) prepareDocument(ctx context.Context, spec contracts.RenderSpec, slotID int) ([]string, error) {
	switch spec.Source.Type {
	case "html":
		return r.prepareHTML(ctx, spec, slotID)
	case "view":
		if htmlMarkup := resolveString(spec.Source.Payload["html"]); htmlMarkup != "" {
			spec.Source.Type = "html"
			spec.Source.Payload = map[string]any{
				"html": htmlMarkup,
			}
			return r.prepareHTML(ctx, spec, slotID)
		}

		return nil, fmt.Errorf("source.type=view requires normalized payload.html before it reaches Stagehand")
	case "url":
		return nil, r.prepareURL(ctx, spec, slotID)
	default:
		return nil, fmt.Errorf("unsupported render source %q", spec.Source.Type)
	}
}

func (r *Renderer) prepareHTML(ctx context.Context, spec contracts.RenderSpec, slotID int) ([]string, error) {
	htmlMarkup := resolveString(spec.Source.Payload["html"])
	if strings.TrimSpace(htmlMarkup) == "" {
		return nil, fmt.Errorf("html render source is missing payload.html")
	}

	baseURL := resolveString(spec.Source.Payload["baseUrl"])
	if baseURL != "" {
		sanitizedBaseURL, err := validateNavigationTarget(baseURL, false, r.config)
		if err != nil {
			return nil, fmt.Errorf("html render source has invalid payload.baseUrl: %w", err)
		}
		baseURL = sanitizedBaseURL
	}
	targetURL := resolveHTMLBootstrapURL(spec)
	warnings := make([]string, 0, 1)

	if err := r.ensureBootstrapURL(ctx, slotID, targetURL); err != nil {
		if targetURL == "about:blank" {
			return warnings, err
		}

		warnings = append(warnings, fmt.Sprintf("base URL %q was unreachable; Stagehand fell back to about:blank", baseURL))
		if fallbackErr := r.ensureBootstrapURL(ctx, slotID, "about:blank"); fallbackErr != nil {
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

func (r *Renderer) prepareURL(ctx context.Context, spec contracts.RenderSpec, slotID int) error {
	targetURL := resolveString(spec.Source.Payload["url"])
	if strings.TrimSpace(targetURL) == "" {
		return fmt.Errorf("url render source is missing payload.url")
	}

	sanitizedTargetURL, err := validateNavigationTarget(targetURL, false, r.config)
	if err != nil {
		return fmt.Errorf("url render source has invalid payload.url: %w", err)
	}
	targetURL = sanitizedTargetURL

	resp, err := chromedp.RunResponse(ctx, chromedp.Navigate(targetURL))
	if err != nil {
		r.clearBootstrapURL(slotID)
		return err
	}

	if resp != nil && resp.Status >= 400 {
		r.clearBootstrapURL(slotID)
		return fmt.Errorf("navigation to %s returned HTTP %d", targetURL, resp.Status)
	}

	r.setBootstrapURL(slotID, targetURL)

	if title := strings.TrimSpace(spec.Document.Title); title != "" {
		return setDocumentTitle(ctx, title)
	}

	return nil
}

func (r *Renderer) waitUntilReady(ctx context.Context, spec contracts.RenderSpec) error {
	waitTimeout := resolveWaitTimeout(spec)
	pollInterval := normalizeReadyPollInterval(r.config.ReadyPollIntervalMs)
	settleFrames := normalizeReadySettleFrames(r.config.ReadySettleFrames)

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
			chromedp.WithPollingInterval(pollInterval),
			chromedp.WithPollingTimeout(waitTimeout),
		),
		chromedp.ActionFunc(func(ctx context.Context) error {
			return waitForAnimationFrames(ctx, settleFrames)
		}),
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

func normalizeReadyPollInterval(intervalMs int) time.Duration {
	if intervalMs <= 0 {
		return 50 * time.Millisecond
	}

	return time.Duration(intervalMs) * time.Millisecond
}

func normalizeReadySettleFrames(frames int) int {
	if frames <= 0 {
		return 0
	}

	return frames
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

func waitForAnimationFrames(ctx context.Context, frames int) error {
	if frames <= 0 {
		return nil
	}

	expression := fmt.Sprintf(`new Promise((resolve) => {
		let remaining = %d;
		const tick = () => {
			remaining -= 1;
			if (remaining <= 0) {
				resolve(true);
				return;
			}

			requestAnimationFrame(tick);
		};

		requestAnimationFrame(tick);
	})`, frames)

	_, exception, err := cdpruntime.Evaluate(expression).
		WithAwaitPromise(true).
		Do(ctx)
	if err != nil {
		return err
	}

	if exception != nil {
		return fmt.Errorf("requestAnimationFrame settle failed: %s", exception.Text)
	}

	return nil
}

func (r *Renderer) ensureBootstrapURL(ctx context.Context, slotID int, targetURL string) error {
	targetURL = strings.TrimSpace(targetURL)
	if targetURL == "" {
		targetURL = "about:blank"
	}

	sanitizedTargetURL, err := validateNavigationTarget(targetURL, true, r.config)
	if err != nil {
		return err
	}
	targetURL = sanitizedTargetURL

	currentURL, known := r.bootstrapURL(slotID)
	if known && currentURL == targetURL {
		return nil
	}

	if !known && targetURL == "about:blank" {
		r.setBootstrapURL(slotID, targetURL)
		return nil
	}

	if err := chromedp.Run(ctx, chromedp.Navigate(targetURL)); err != nil {
		r.clearBootstrapURL(slotID)
		return err
	}

	r.setBootstrapURL(slotID, targetURL)

	return nil
}

func validateNavigationTarget(raw string, allowAboutBlank bool, cfg config.RuntimeConfig) (string, error) {
	target := strings.TrimSpace(raw)
	if target == "" {
		return "", fmt.Errorf("target URL is empty")
	}

	if allowAboutBlank && target == "about:blank" {
		return target, nil
	}

	parsed, err := url.Parse(target)
	if err != nil {
		return "", err
	}

	if parsed.User != nil {
		return "", fmt.Errorf("embedded credentials are not allowed")
	}

	switch strings.ToLower(strings.TrimSpace(parsed.Scheme)) {
	case "http", "https":
		host := strings.TrimSpace(parsed.Hostname())
		if host == "" {
			return "", fmt.Errorf("host is required")
		}

		if err := validateTargetHost(host, cfg); err != nil {
			return "", err
		}

		return parsed.String(), nil
	case "about":
		if allowAboutBlank && target == "about:blank" {
			return target, nil
		}

		return "", fmt.Errorf("about URLs are limited to about:blank")
	default:
		return "", fmt.Errorf("scheme %q is not allowed", parsed.Scheme)
	}
}

func validateTargetHost(host string, cfg config.RuntimeConfig) error {
	normalizedHost := strings.ToLower(strings.TrimSpace(host))
	if normalizedHost == "" {
		return fmt.Errorf("host is required")
	}

	allowedHosts := parseAllowedTargetHosts(cfg.AllowedTargetHosts)
	if len(allowedHosts) > 0 && !hostMatchesAllowedPatterns(normalizedHost, allowedHosts) {
		return fmt.Errorf("host %q is not allowed by the Stagehand navigation policy", host)
	}

	if cfg.AllowPrivateTargets {
		return nil
	}

	if isImplicitlyPrivateHost(normalizedHost) {
		return fmt.Errorf("host %q resolves to a private or loopback target and private targets are disabled", host)
	}

	if ip := net.ParseIP(normalizedHost); ip != nil {
		if !isPublicIP(ip) {
			return fmt.Errorf("host %q resolves to a private or loopback target and private targets are disabled", host)
		}

		return nil
	}

	ips, err := net.LookupIP(normalizedHost)
	if err != nil {
		return nil
	}

	for _, ip := range ips {
		if !isPublicIP(ip) {
			return fmt.Errorf("host %q resolves to a private or loopback target and private targets are disabled", host)
		}
	}

	return nil
}

func parseAllowedTargetHosts(raw string) []string {
	parts := strings.Split(raw, ",")
	allowed := make([]string, 0, len(parts))

	for _, part := range parts {
		pattern := strings.ToLower(strings.TrimSpace(part))
		if pattern == "" {
			continue
		}

		allowed = append(allowed, pattern)
	}

	return allowed
}

func hostMatchesAllowedPatterns(host string, patterns []string) bool {
	for _, pattern := range patterns {
		if pattern == host {
			return true
		}

		if strings.HasPrefix(pattern, "*.") {
			suffix := strings.TrimPrefix(pattern, "*")
			if suffix != "" && strings.HasSuffix(host, suffix) {
				return true
			}
		}
	}

	return false
}

func isImplicitlyPrivateHost(host string) bool {
	if host == "localhost" || strings.HasSuffix(host, ".localhost") || strings.HasSuffix(host, ".local") {
		return true
	}

	return !strings.Contains(host, ".")
}

func isPublicIP(ip net.IP) bool {
	return !(ip.IsLoopback() || ip.IsPrivate() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() || ip.IsMulticast() || ip.IsUnspecified())
}

func (r *Renderer) bootstrapURL(slotID int) (string, bool) {
	r.pageMu.RLock()
	defer r.pageMu.RUnlock()

	url, ok := r.pages[slotID]

	return url, ok
}

func (r *Renderer) setBootstrapURL(slotID int, targetURL string) {
	r.pageMu.Lock()
	defer r.pageMu.Unlock()

	r.pages[slotID] = targetURL
}

func (r *Renderer) clearBootstrapURL(slotID int) {
	r.pageMu.Lock()
	defer r.pageMu.Unlock()

	delete(r.pages, slotID)
}

func resolveHTMLBootstrapURL(spec contracts.RenderSpec) string {
	baseURL := resolveString(spec.Source.Payload["baseUrl"])
	if baseURL == "" {
		return "about:blank"
	}

	if shouldRenderHTMLFromBlank(spec.Source.Payload) {
		return "about:blank"
	}

	return baseURL
}

func shouldRenderHTMLFromBlank(payload map[string]any) bool {
	origin, ok := payload["origin"].(map[string]any)
	if !ok {
		return false
	}

	return resolveString(origin["type"]) == "view"
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
