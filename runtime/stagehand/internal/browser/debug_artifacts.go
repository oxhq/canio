package browser

import (
	"context"
	"encoding/json"
	"strings"
	"sync"
	"time"

	"github.com/chromedp/cdproto/cdp"
	"github.com/chromedp/cdproto/network"
	"github.com/chromedp/cdproto/page"
	cdpruntime "github.com/chromedp/cdproto/runtime"
	"github.com/chromedp/chromedp"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
)

type debugArtifactsCollector struct {
	mu      sync.Mutex
	console []contracts.ConsoleEvent
	network []contracts.NetworkEvent
}

func newDebugArtifactsCollector(ctx context.Context) *debugArtifactsCollector {
	collector := &debugArtifactsCollector{
		console: make([]contracts.ConsoleEvent, 0, 8),
		network: make([]contracts.NetworkEvent, 0, 16),
	}

	chromedp.ListenTarget(ctx, func(event any) {
		collector.handleEvent(event)
	})

	return collector
}

func (c *debugArtifactsCollector) handleEvent(event any) {
	c.mu.Lock()
	defer c.mu.Unlock()

	switch payload := event.(type) {
	case *cdpruntime.EventConsoleAPICalled:
		c.console = append(c.console, contracts.ConsoleEvent{
			Timestamp: formatRuntimeTimestamp(payload.Timestamp),
			Type:      string(payload.Type),
			Message:   formatConsoleMessage(payload.Args),
			URL:       stackTraceURL(payload.StackTrace),
			Line:      stackTraceLine(payload.StackTrace),
			Column:    stackTraceColumn(payload.StackTrace),
		})
	case *cdpruntime.EventExceptionThrown:
		c.console = append(c.console, contracts.ConsoleEvent{
			Timestamp: formatRuntimeTimestamp(payload.Timestamp),
			Type:      "exception",
			Message:   formatException(payload.ExceptionDetails),
			URL:       exceptionURL(payload.ExceptionDetails),
			Line:      exceptionLine(payload.ExceptionDetails),
			Column:    exceptionColumn(payload.ExceptionDetails),
		})
	case *network.EventRequestWillBeSent:
		c.network = append(c.network, contracts.NetworkEvent{
			Timestamp: formatMonotonicTimestamp(payload.Timestamp),
			Stage:     "request",
			RequestID: string(payload.RequestID),
			URL:       networkRequestURL(payload.Request),
			Method:    networkRequestMethod(payload.Request),
			Resource:  string(payload.Type),
		})
	case *network.EventResponseReceived:
		c.network = append(c.network, contracts.NetworkEvent{
			Timestamp: formatMonotonicTimestamp(payload.Timestamp),
			Stage:     "response",
			RequestID: string(payload.RequestID),
			URL:       networkResponseURL(payload.Response),
			Status:    int64(networkResponseStatus(payload.Response)),
			MimeType:  networkResponseMimeType(payload.Response),
			Resource:  string(payload.Type),
		})
	case *network.EventLoadingFailed:
		c.network = append(c.network, contracts.NetworkEvent{
			Timestamp: formatMonotonicTimestamp(payload.Timestamp),
			Stage:     "failed",
			RequestID: string(payload.RequestID),
			Resource:  string(payload.Type),
			Error:     strings.TrimSpace(payload.ErrorText),
		})
	}
}

func (c *debugArtifactsCollector) Finalize(ctx context.Context) (*contracts.DebugArtifacts, []string) {
	c.mu.Lock()
	artifacts := &contracts.DebugArtifacts{
		Console: append([]contracts.ConsoleEvent(nil), c.console...),
		Network: append([]contracts.NetworkEvent(nil), c.network...),
	}
	c.mu.Unlock()

	warnings := make([]string, 0, 2)

	screenshot, err := capturePageScreenshot(ctx)
	if err != nil {
		warnings = append(warnings, "failed to capture page screenshot: "+err.Error())
	} else {
		artifacts.ScreenshotPNG = screenshot
	}

	domSnapshot, err := captureDOMSnapshot(ctx)
	if err != nil {
		warnings = append(warnings, "failed to capture DOM snapshot: "+err.Error())
	} else {
		artifacts.DOMSnapshot = domSnapshot
	}

	return artifacts, warnings
}

func capturePageScreenshot(ctx context.Context) ([]byte, error) {
	var screenshot []byte
	err := chromedp.Run(ctx, chromedp.ActionFunc(func(ctx context.Context) error {
		var err error
		screenshot, err = page.CaptureScreenshot().
			WithFormat(page.CaptureScreenshotFormatPng).
			WithCaptureBeyondViewport(true).
			Do(ctx)
		return err
	}))

	return screenshot, err
}

func captureDOMSnapshot(ctx context.Context) (string, error) {
	var snapshot string
	err := chromedp.Run(ctx, chromedp.Evaluate(`(() => {
		const root = document.documentElement ? document.documentElement.outerHTML : "";
		const doctype = document.doctype ? new XMLSerializer().serializeToString(document.doctype) + "\n" : "";
		return doctype + root;
	})()`, &snapshot))
	return snapshot, err
}

func formatConsoleMessage(args []*cdpruntime.RemoteObject) string {
	if len(args) == 0 {
		return ""
	}

	parts := make([]string, 0, len(args))
	for _, arg := range args {
		value := strings.TrimSpace(formatRemoteObject(arg))
		if value == "" {
			continue
		}

		parts = append(parts, value)
	}

	return strings.Join(parts, " ")
}

func formatRemoteObject(arg *cdpruntime.RemoteObject) string {
	if arg == nil {
		return ""
	}

	if value := strings.TrimSpace(formatJSONValue(arg.Value)); value != "" {
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

func formatJSONValue(value []byte) string {
	if len(value) == 0 {
		return ""
	}

	var decoded any
	if err := json.Unmarshal(value, &decoded); err != nil {
		return string(value)
	}

	switch typed := decoded.(type) {
	case string:
		return typed
	default:
		normalized, err := json.Marshal(typed)
		if err != nil {
			return string(value)
		}
		return string(normalized)
	}
}

func formatException(details *cdpruntime.ExceptionDetails) string {
	if details == nil {
		return "JavaScript exception"
	}

	message := strings.TrimSpace(details.Text)
	if details.Exception != nil {
		if description := strings.TrimSpace(formatRemoteObject(details.Exception)); description != "" {
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

func formatRuntimeTimestamp(value *cdpruntime.Timestamp) string {
	if value == nil {
		return ""
	}

	return value.Time().UTC().Format(time.RFC3339Nano)
}

func formatMonotonicTimestamp(value *cdp.MonotonicTime) string {
	if value == nil {
		return ""
	}

	return value.Time().UTC().Format(time.RFC3339Nano)
}

func stackTraceURL(trace *cdpruntime.StackTrace) string {
	frame := firstStackTraceFrame(trace)
	if frame == nil {
		return ""
	}

	return strings.TrimSpace(frame.URL)
}

func stackTraceLine(trace *cdpruntime.StackTrace) int64 {
	frame := firstStackTraceFrame(trace)
	if frame == nil {
		return 0
	}

	return frame.LineNumber + 1
}

func stackTraceColumn(trace *cdpruntime.StackTrace) int64 {
	frame := firstStackTraceFrame(trace)
	if frame == nil {
		return 0
	}

	return frame.ColumnNumber + 1
}

func firstStackTraceFrame(trace *cdpruntime.StackTrace) *cdpruntime.CallFrame {
	if trace == nil || len(trace.CallFrames) == 0 {
		return nil
	}

	return trace.CallFrames[0]
}

func exceptionURL(details *cdpruntime.ExceptionDetails) string {
	if details == nil {
		return ""
	}

	if value := strings.TrimSpace(details.URL); value != "" {
		return value
	}

	return stackTraceURL(details.StackTrace)
}

func exceptionLine(details *cdpruntime.ExceptionDetails) int64 {
	if details == nil {
		return 0
	}

	if details.LineNumber > 0 {
		return details.LineNumber + 1
	}

	return stackTraceLine(details.StackTrace)
}

func exceptionColumn(details *cdpruntime.ExceptionDetails) int64 {
	if details == nil {
		return 0
	}

	if details.ColumnNumber > 0 {
		return details.ColumnNumber + 1
	}

	return stackTraceColumn(details.StackTrace)
}

func networkRequestURL(request *network.Request) string {
	if request == nil {
		return ""
	}

	return strings.TrimSpace(request.URL)
}

func networkRequestMethod(request *network.Request) string {
	if request == nil {
		return ""
	}

	return strings.TrimSpace(request.Method)
}

func networkResponseURL(response *network.Response) string {
	if response == nil {
		return ""
	}

	return strings.TrimSpace(response.URL)
}

func networkResponseStatus(response *network.Response) int {
	if response == nil {
		return 0
	}

	return int(response.Status)
}

func networkResponseMimeType(response *network.Response) string {
	if response == nil {
		return ""
	}

	return strings.TrimSpace(response.MimeType)
}
