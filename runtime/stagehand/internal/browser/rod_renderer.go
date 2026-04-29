package browser

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"strings"
	"sync"
	"time"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/proto"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

func (r *Renderer) renderWithRod(ctx context.Context, spec contracts.RenderSpec, slotID int, basePage *rod.Page, timings map[string]int64, renderStart time.Time) ([]byte, []string, *contracts.DebugArtifacts, map[string]int64, error) {
	rodPage := basePage.Context(ctx)

	var artifactsCollector *rodDebugArtifactsCollector
	if contracts.DebugArtifactsEnabled(spec) {
		artifactsCollector = newRodDebugArtifactsCollector(ctx, rodPage)
	}

	startedAt := time.Now()
	warnings, err := r.prepareDocumentWithRod(rodPage, spec, slotID)
	if err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["prepareMs"] = time.Since(startedAt).Milliseconds()

	startedAt = time.Now()
	if err := r.waitUntilReadyWithRod(rodPage, spec); err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["waitMs"] = time.Since(startedAt).Milliseconds()

	startedAt = time.Now()
	pdfData, err := printToPDFWithRod(rodPage, spec)
	if err != nil {
		return nil, warnings, nil, timings, err
	}
	timings["printMs"] = time.Since(startedAt).Milliseconds()

	var debugArtifacts *contracts.DebugArtifacts
	if artifactsCollector != nil {
		var artifactWarnings []string
		startedAt = time.Now()
		debugArtifacts, artifactWarnings = artifactsCollector.Finalize(rodPage)
		timings["debugArtifactsMs"] = time.Since(startedAt).Milliseconds()
		warnings = append(warnings, artifactWarnings...)
	}

	timings["renderMs"] = time.Since(renderStart).Milliseconds()

	return pdfData, warnings, debugArtifacts, timings, nil
}

func (r *Renderer) prepareDocumentWithRod(rodPage *rod.Page, spec contracts.RenderSpec, slotID int) ([]string, error) {
	switch spec.Source.Type {
	case "html":
		return r.prepareHTMLWithRod(rodPage, spec, slotID)
	case "view":
		if htmlMarkup := resolveString(spec.Source.Payload["html"]); htmlMarkup != "" {
			spec.Source.Type = "html"
			spec.Source.Payload = map[string]any{
				"html": htmlMarkup,
			}
			return r.prepareHTMLWithRod(rodPage, spec, slotID)
		}

		return nil, fmt.Errorf("source.type=view requires normalized payload.html before it reaches Stagehand")
	case "url":
		return nil, r.prepareURLWithRod(rodPage, spec, slotID)
	default:
		return nil, fmt.Errorf("unsupported render source %q", spec.Source.Type)
	}
}

func (r *Renderer) prepareHTMLWithRod(rodPage *rod.Page, spec contracts.RenderSpec, slotID int) ([]string, error) {
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

	if err := r.ensureBootstrapURLWithRod(rodPage, slotID, targetURL); err != nil {
		if targetURL == "about:blank" {
			return warnings, err
		}

		warnings = append(warnings, fmt.Sprintf("base URL %q was unreachable; Stagehand fell back to about:blank", baseURL))
		if fallbackErr := r.ensureBootstrapURLWithRod(rodPage, slotID, "about:blank"); fallbackErr != nil {
			return warnings, fallbackErr
		}
	}

	if baseURL != "" {
		htmlMarkup = injectBaseHref(htmlMarkup, baseURL)
	}

	if err := (proto.PageSetBypassCSP{Enabled: true}).Call(rodPage); err != nil {
		return warnings, err
	}

	if err := rodPage.SetDocumentContent(htmlMarkup); err != nil {
		return warnings, err
	}

	if title := strings.TrimSpace(spec.Document.Title); title != "" {
		if err := setDocumentTitleWithRod(rodPage, title); err != nil {
			return warnings, err
		}
	}

	return warnings, nil
}

func (r *Renderer) prepareURLWithRod(rodPage *rod.Page, spec contracts.RenderSpec, slotID int) error {
	targetURL := resolveString(spec.Source.Payload["url"])
	if strings.TrimSpace(targetURL) == "" {
		return fmt.Errorf("url render source is missing payload.url")
	}

	sanitizedTargetURL, err := validateNavigationTarget(targetURL, false, r.config)
	if err != nil {
		return fmt.Errorf("url render source has invalid payload.url: %w", err)
	}
	targetURL = sanitizedTargetURL

	nav, response, err := navigateWithRod(rodPage, targetURL)
	if err != nil {
		r.clearBootstrapURL(slotID)
		return err
	}
	if nav != nil && strings.TrimSpace(nav.ErrorText) != "" {
		r.clearBootstrapURL(slotID)
		return fmt.Errorf("navigation to %s failed: %s", targetURL, strings.TrimSpace(nav.ErrorText))
	}
	if response != nil && response.Response != nil && response.Response.Status >= 400 {
		r.clearBootstrapURL(slotID)
		return fmt.Errorf("navigation to %s returned HTTP %d", targetURL, response.Response.Status)
	}

	r.setBootstrapURL(slotID, targetURL)

	if title := strings.TrimSpace(spec.Document.Title); title != "" {
		return setDocumentTitleWithRod(rodPage, title)
	}

	return nil
}

func (r *Renderer) waitUntilReadyWithRod(rodPage *rod.Page, spec contracts.RenderSpec) error {
	waitTimeout := resolveWaitTimeout(spec)
	pollInterval := normalizeReadyPollInterval(r.config.ReadyPollIntervalMs)
	settleFrames := normalizeReadySettleFrames(r.config.ReadySettleFrames)

	waitPage := rodPage.Timeout(waitTimeout)
	if err := waitPage.Wait(&rod.EvalOptions{
		ByValue: true,
		JS: fmt.Sprintf(`() => new Promise((resolve) => {
			const intervalMs = %d;
			const ready = () => {
				const documentReady = document.readyState === "complete";
				const bodyReady = !!document.body;
				const fontsReady = !document.fonts || document.fonts.status === "loaded";
				const imagesReady = Array.from(document.images || []).every((img) => img.complete);
				const canioReady = typeof window.__CANIO_READY__ === "undefined" || window.__CANIO_READY__ === true;
				const legacyReady = typeof window.CANIO_READY === "undefined" || window.CANIO_READY === true;
				return bodyReady && documentReady && fontsReady && imagesReady && canioReady && legacyReady;
			};
			if (ready()) {
				resolve(true);
				return;
			}
			const timer = setInterval(() => {
				if (!ready()) {
					return;
				}
				clearInterval(timer);
				resolve(true);
			}, intervalMs);
		})`, pollInterval.Milliseconds()),
		AwaitPromise: true,
	}); err != nil {
		return err
	}

	return waitForAnimationFramesWithRod(rodPage, settleFrames)
}

func waitForAnimationFramesWithRod(rodPage *rod.Page, frames int) error {
	if frames <= 0 {
		return nil
	}

	expression := fmt.Sprintf(`() => new Promise((resolve) => {
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

	_, err := rodPage.Evaluate(&rod.EvalOptions{
		ByValue:      true,
		AwaitPromise: true,
		JS:           expression,
	})
	if err != nil {
		return fmt.Errorf("requestAnimationFrame settle failed: %w", err)
	}

	return nil
}

func printToPDFWithRod(rodPage *rod.Page, spec contracts.RenderSpec) ([]byte, error) {
	presentation := spec.Presentation
	params := &proto.PagePrintToPDF{
		PrintBackground:   resolveBool(presentation["background"], true),
		Landscape:         resolveBool(presentation["landscape"], false),
		GenerateTaggedPDF: spec.Document.Tagged,
	}

	if scale, ok := resolveFloat(presentation["scale"]); ok && scale > 0 {
		params.Scale = float64Ptr(scale)
	}

	if pageRanges := resolveString(presentation["pageRanges"]); pageRanges != "" {
		params.PageRanges = pageRanges
	}

	if width, height, ok := resolvePaperSize(presentation); ok {
		params.PaperWidth = float64Ptr(width)
		params.PaperHeight = float64Ptr(height)
	}

	if margins, ok := resolveMargins(presentation["margins"]); ok {
		params.MarginTop = float64Ptr(margins[0])
		params.MarginRight = float64Ptr(margins[1])
		params.MarginBottom = float64Ptr(margins[2])
		params.MarginLeft = float64Ptr(margins[3])
	}

	if headerTemplate, footerTemplate, display := resolveHeaderAndFooter(spec); display {
		params.DisplayHeaderFooter = true

		if headerTemplate != "" {
			params.HeaderTemplate = headerTemplate
		}

		if footerTemplate != "" {
			params.FooterTemplate = footerTemplate
		}
	}

	stream, err := rodPage.PDF(params)
	if err != nil {
		return nil, err
	}
	defer stream.Close()

	return io.ReadAll(stream)
}

func (r *Renderer) ensureBootstrapURLWithRod(rodPage *rod.Page, slotID int, targetURL string) error {
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

	nav, _, err := navigateWithRod(rodPage, targetURL)
	if err != nil {
		r.clearBootstrapURL(slotID)
		return err
	}
	if nav != nil && strings.TrimSpace(nav.ErrorText) != "" {
		r.clearBootstrapURL(slotID)
		return fmt.Errorf("navigation to %s failed: %s", targetURL, strings.TrimSpace(nav.ErrorText))
	}

	r.setBootstrapURL(slotID, targetURL)

	return nil
}

func navigateWithRod(rodPage *rod.Page, targetURL string) (*proto.PageNavigateResult, *proto.NetworkResponseReceived, error) {
	eventCtx, eventCancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer eventCancel()

	eventPage := rodPage.Context(eventCtx)
	_ = proto.NetworkEnable{}.Call(eventPage)

	responseCh := make(chan *proto.NetworkResponseReceived, 1)
	go eventPage.EachEvent(func(event *proto.NetworkResponseReceived) bool {
		if event == nil || event.Response == nil {
			return false
		}

		if !rodResponseMatchesNavigation(event.Response.URL, targetURL) {
			return false
		}

		select {
		case responseCh <- event:
		default:
		}

		return true
	})()

	nav, err := (proto.PageNavigate{URL: targetURL}).Call(rodPage)
	if err != nil {
		return nil, nil, err
	}

	select {
	case response := <-responseCh:
		return nav, response, nil
	case <-time.After(500 * time.Millisecond):
		return nav, nil, nil
	}
}

func rodResponseMatchesNavigation(responseURL string, targetURL string) bool {
	responseURL = strings.TrimSpace(responseURL)
	targetURL = strings.TrimSpace(targetURL)

	if responseURL == targetURL {
		return true
	}

	return strings.TrimSuffix(responseURL, "/") == strings.TrimSuffix(targetURL, "/")
}

func setDocumentTitleWithRod(rodPage *rod.Page, title string) error {
	_, err := rodPage.Eval(`(title) => {
		document.title = title;
		return true;
	}`, title)
	return err
}

type rodDebugArtifactsCollector struct {
	mu      sync.Mutex
	console []contracts.ConsoleEvent
	network []contracts.NetworkEvent
}

func newRodDebugArtifactsCollector(ctx context.Context, rodPage *rod.Page) *rodDebugArtifactsCollector {
	collector := &rodDebugArtifactsCollector{
		console: make([]contracts.ConsoleEvent, 0, 8),
		network: make([]contracts.NetworkEvent, 0, 16),
	}

	eventPage := rodPage.Context(ctx)
	_ = proto.RuntimeEnable{}.Call(eventPage)
	_ = proto.NetworkEnable{}.Call(eventPage)

	go eventPage.EachEvent(
		func(event *proto.RuntimeConsoleAPICalled) {
			collector.handleConsoleEvent(event)
		},
		func(event *proto.RuntimeExceptionThrown) {
			collector.handleExceptionEvent(event)
		},
		func(event *proto.NetworkRequestWillBeSent) {
			collector.handleNetworkRequestEvent(event)
		},
		func(event *proto.NetworkResponseReceived) {
			collector.handleNetworkResponseEvent(event)
		},
		func(event *proto.NetworkLoadingFailed) {
			collector.handleNetworkFailedEvent(event)
		},
	)()

	return collector
}

func (c *rodDebugArtifactsCollector) handleConsoleEvent(event *proto.RuntimeConsoleAPICalled) {
	if event == nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	c.console = append(c.console, contracts.ConsoleEvent{
		Timestamp: formatRodRuntimeTimestamp(event.Timestamp),
		Type:      string(event.Type),
		Message:   formatRodConsoleMessage(event.Args),
		URL:       rodStackTraceURL(event.StackTrace),
		Line:      rodStackTraceLine(event.StackTrace),
		Column:    rodStackTraceColumn(event.StackTrace),
	})
}

func (c *rodDebugArtifactsCollector) handleExceptionEvent(event *proto.RuntimeExceptionThrown) {
	if event == nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	c.console = append(c.console, contracts.ConsoleEvent{
		Timestamp: formatRodRuntimeTimestamp(event.Timestamp),
		Type:      "exception",
		Message:   formatRodException(event.ExceptionDetails),
		URL:       rodExceptionURL(event.ExceptionDetails),
		Line:      rodExceptionLine(event.ExceptionDetails),
		Column:    rodExceptionColumn(event.ExceptionDetails),
	})
}

func (c *rodDebugArtifactsCollector) handleNetworkRequestEvent(event *proto.NetworkRequestWillBeSent) {
	if event == nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	c.network = append(c.network, contracts.NetworkEvent{
		Timestamp: formatRodWallTime(event.WallTime),
		Stage:     "request",
		RequestID: string(event.RequestID),
		URL:       rodNetworkRequestURL(event.Request),
		Method:    rodNetworkRequestMethod(event.Request),
		Resource:  string(event.Type),
	})
}

func (c *rodDebugArtifactsCollector) handleNetworkResponseEvent(event *proto.NetworkResponseReceived) {
	if event == nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	c.network = append(c.network, contracts.NetworkEvent{
		Timestamp: formatRodMonotonicTimestamp(event.Timestamp),
		Stage:     "response",
		RequestID: string(event.RequestID),
		URL:       rodNetworkResponseURL(event.Response),
		Status:    int64(rodNetworkResponseStatus(event.Response)),
		MimeType:  rodNetworkResponseMimeType(event.Response),
		Resource:  string(event.Type),
	})
}

func (c *rodDebugArtifactsCollector) handleNetworkFailedEvent(event *proto.NetworkLoadingFailed) {
	if event == nil {
		return
	}

	c.mu.Lock()
	defer c.mu.Unlock()

	c.network = append(c.network, contracts.NetworkEvent{
		Timestamp: formatRodMonotonicTimestamp(event.Timestamp),
		Stage:     "failed",
		RequestID: string(event.RequestID),
		Resource:  string(event.Type),
		Error:     strings.TrimSpace(event.ErrorText),
	})
}

func (c *rodDebugArtifactsCollector) Finalize(rodPage *rod.Page) (*contracts.DebugArtifacts, []string) {
	c.mu.Lock()
	artifacts := &contracts.DebugArtifacts{
		Console: append([]contracts.ConsoleEvent(nil), c.console...),
		Network: append([]contracts.NetworkEvent(nil), c.network...),
	}
	c.mu.Unlock()

	warnings := make([]string, 0, 2)

	screenshot, err := capturePageScreenshotWithRod(rodPage)
	if err != nil {
		warnings = append(warnings, "failed to capture page screenshot: "+err.Error())
	} else {
		artifacts.ScreenshotPNG = screenshot
	}

	domSnapshot, err := captureDOMSnapshotWithRod(rodPage)
	if err != nil {
		warnings = append(warnings, "failed to capture DOM snapshot: "+err.Error())
	} else {
		artifacts.DOMSnapshot = domSnapshot
	}

	return artifacts, warnings
}

func capturePageScreenshotWithRod(rodPage *rod.Page) ([]byte, error) {
	return rodPage.Screenshot(true, &proto.PageCaptureScreenshot{
		Format:                proto.PageCaptureScreenshotFormatPng,
		CaptureBeyondViewport: true,
	})
}

func captureDOMSnapshotWithRod(rodPage *rod.Page) (string, error) {
	result, err := rodPage.Eval(`() => {
		const root = document.documentElement ? document.documentElement.outerHTML : "";
		const doctype = document.doctype ? new XMLSerializer().serializeToString(document.doctype) + "\n" : "";
		return doctype + root;
	}`)
	if err != nil {
		return "", err
	}

	return strings.TrimSpace(rodRemoteObjectValue(result)), nil
}

func formatRodConsoleMessage(args []*proto.RuntimeRemoteObject) string {
	if len(args) == 0 {
		return ""
	}

	parts := make([]string, 0, len(args))
	for _, arg := range args {
		value := strings.TrimSpace(formatRodRemoteObject(arg))
		if value == "" {
			continue
		}

		parts = append(parts, value)
	}

	return strings.Join(parts, " ")
}

func formatRodRemoteObject(arg *proto.RuntimeRemoteObject) string {
	if arg == nil {
		return ""
	}

	if value := strings.TrimSpace(rodRemoteObjectValue(arg)); value != "" {
		return value
	}

	if value := strings.TrimSpace(string(arg.UnserializableValue)); value != "" {
		return value
	}

	if value := strings.TrimSpace(arg.Description); value != "" {
		return value
	}

	if arg.Preview != nil {
		if value := strings.TrimSpace(arg.Preview.Description); value != "" {
			return value
		}
	}

	if value := strings.TrimSpace(arg.ClassName); value != "" {
		return value
	}

	return string(arg.Type)
}

func rodRemoteObjectValue(arg *proto.RuntimeRemoteObject) string {
	if arg == nil {
		return ""
	}

	raw := arg.Value.Val()
	if raw == nil {
		return ""
	}

	switch value := raw.(type) {
	case string:
		return value
	default:
		encoded, err := json.Marshal(value)
		if err != nil {
			return arg.Value.String()
		}
		return string(encoded)
	}
}

func formatRodException(details *proto.RuntimeExceptionDetails) string {
	if details == nil {
		return "JavaScript exception"
	}

	message := strings.TrimSpace(details.Text)
	if details.Exception != nil {
		if description := strings.TrimSpace(formatRodRemoteObject(details.Exception)); description != "" {
			if message == "" {
				message = description
			} else if !strings.Contains(message, description) {
				message += ": " + description
			}
		}
	}

	if message == "" {
		message = "JavaScript exception"
	}

	return message
}

func formatRodRuntimeTimestamp(value proto.RuntimeTimestamp) string {
	if value <= 0 {
		return ""
	}

	return time.UnixMilli(int64(value)).UTC().Format(time.RFC3339Nano)
}

func formatRodWallTime(value proto.TimeSinceEpoch) string {
	if value <= 0 {
		return ""
	}

	return value.Time().UTC().Format(time.RFC3339Nano)
}

func formatRodMonotonicTimestamp(value proto.MonotonicTime) string {
	if value <= 0 {
		return ""
	}

	return value.String()
}

func rodStackTraceURL(trace *proto.RuntimeStackTrace) string {
	frame := firstRodStackTraceFrame(trace)
	if frame == nil {
		return ""
	}

	return strings.TrimSpace(frame.URL)
}

func rodStackTraceLine(trace *proto.RuntimeStackTrace) int64 {
	frame := firstRodStackTraceFrame(trace)
	if frame == nil {
		return 0
	}

	return int64(frame.LineNumber + 1)
}

func rodStackTraceColumn(trace *proto.RuntimeStackTrace) int64 {
	frame := firstRodStackTraceFrame(trace)
	if frame == nil {
		return 0
	}

	return int64(frame.ColumnNumber + 1)
}

func firstRodStackTraceFrame(trace *proto.RuntimeStackTrace) *proto.RuntimeCallFrame {
	if trace == nil || len(trace.CallFrames) == 0 {
		return nil
	}

	return trace.CallFrames[0]
}

func rodExceptionURL(details *proto.RuntimeExceptionDetails) string {
	if details == nil {
		return ""
	}

	if value := strings.TrimSpace(details.URL); value != "" {
		return value
	}

	return rodStackTraceURL(details.StackTrace)
}

func rodExceptionLine(details *proto.RuntimeExceptionDetails) int64 {
	if details == nil {
		return 0
	}

	if details.LineNumber > 0 {
		return int64(details.LineNumber + 1)
	}

	return rodStackTraceLine(details.StackTrace)
}

func rodExceptionColumn(details *proto.RuntimeExceptionDetails) int64 {
	if details == nil {
		return 0
	}

	if details.ColumnNumber > 0 {
		return int64(details.ColumnNumber + 1)
	}

	return rodStackTraceColumn(details.StackTrace)
}

func rodNetworkRequestURL(request *proto.NetworkRequest) string {
	if request == nil {
		return ""
	}

	return strings.TrimSpace(request.URL)
}

func rodNetworkRequestMethod(request *proto.NetworkRequest) string {
	if request == nil {
		return ""
	}

	return strings.TrimSpace(request.Method)
}

func rodNetworkResponseURL(response *proto.NetworkResponse) string {
	if response == nil {
		return ""
	}

	return strings.TrimSpace(response.URL)
}

func rodNetworkResponseStatus(response *proto.NetworkResponse) int {
	if response == nil {
		return 0
	}

	return response.Status
}

func rodNetworkResponseMimeType(response *proto.NetworkResponse) string {
	if response == nil {
		return ""
	}

	return strings.TrimSpace(response.MIMEType)
}

func float64Ptr(value float64) *float64 {
	return &value
}
