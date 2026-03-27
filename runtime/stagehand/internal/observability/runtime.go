package observability

import (
	"fmt"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
)

type Runtime struct {
	startedAt time.Time

	mu sync.RWMutex

	requests            map[requestKey]requestMetric
	renders             map[string]uint64
	renderDurationSum   float64
	renderDurationCount uint64
	jobEvents           map[string]uint64
	webhooks            map[string]uint64
}

type requestKey struct {
	Method string
	Route  string
	Status int
}

type requestMetric struct {
	Count       uint64
	DurationSum float64
}

func NewRuntime(startedAt time.Time) *Runtime {
	return &Runtime{
		startedAt: startedAt.UTC(),
		requests:  map[requestKey]requestMetric{},
		renders: map[string]uint64{
			"completed": 0,
			"failed":    0,
		},
		jobEvents: map[string]uint64{},
		webhooks: map[string]uint64{
			"success": 0,
			"failure": 0,
		},
	}
}

func (r *Runtime) RecordHTTPRequest(method string, route string, status int, duration time.Duration) {
	if r == nil {
		return
	}

	key := requestKey{
		Method: strings.ToUpper(strings.TrimSpace(method)),
		Route:  normalizeMetricRoute(route),
		Status: status,
	}

	r.mu.Lock()
	current := r.requests[key]
	current.Count++
	current.DurationSum += duration.Seconds()
	r.requests[key] = current
	r.mu.Unlock()
}

func (r *Runtime) RecordRender(success bool, duration time.Duration) {
	if r == nil {
		return
	}

	status := "failed"
	if success {
		status = "completed"
	}

	r.mu.Lock()
	r.renders[status]++
	r.renderDurationCount++
	r.renderDurationSum += duration.Seconds()
	r.mu.Unlock()
}

func (r *Runtime) RecordJobEvent(kind events.Kind) {
	if r == nil {
		return
	}

	label := strings.TrimSpace(string(kind))
	if label == "" {
		return
	}

	r.mu.Lock()
	r.jobEvents[label]++
	r.mu.Unlock()
}

func (r *Runtime) RecordWebhook(success bool) {
	if r == nil {
		return
	}

	label := "failure"
	if success {
		label = "success"
	}

	r.mu.Lock()
	r.webhooks[label]++
	r.mu.Unlock()
}

func (r *Runtime) Prometheus(status contracts.RuntimeStatus) string {
	if r == nil {
		return ""
	}

	r.mu.RLock()
	requests := make(map[requestKey]requestMetric, len(r.requests))
	for key, value := range r.requests {
		requests[key] = value
	}
	renders := make(map[string]uint64, len(r.renders))
	for key, value := range r.renders {
		renders[key] = value
	}
	jobEvents := make(map[string]uint64, len(r.jobEvents))
	for key, value := range r.jobEvents {
		jobEvents[key] = value
	}
	webhooks := make(map[string]uint64, len(r.webhooks))
	for key, value := range r.webhooks {
		webhooks[key] = value
	}
	renderDurationSum := r.renderDurationSum
	renderDurationCount := r.renderDurationCount
	startedAt := r.startedAt
	r.mu.RUnlock()

	var builder strings.Builder

	writeHelp(&builder, "canio_runtime_up", "Whether the Stagehand runtime is reporting as healthy.")
	writeMetric(&builder, "canio_runtime_up", nil, "1")
	writeHelp(&builder, "canio_runtime_started_at_seconds", "Unix timestamp when the runtime booted.")
	writeMetric(&builder, "canio_runtime_started_at_seconds", nil, strconv.FormatInt(startedAt.Unix(), 10))
	writeHelp(&builder, "canio_runtime_render_count", "Total completed renders since runtime boot.")
	writeMetric(&builder, "canio_runtime_render_count", nil, strconv.Itoa(status.Runtime.RenderCount))
	writeHelp(&builder, "canio_runtime_queue_depth", "Current async queue depth, including scheduled retries.")
	writeMetric(&builder, "canio_runtime_queue_depth", nil, strconv.Itoa(status.Queue.Depth))
	writeHelp(&builder, "canio_runtime_browser_pool_size", "Configured browser pool capacity.")
	writeMetric(&builder, "canio_runtime_browser_pool_size", nil, strconv.Itoa(status.BrowserPool.Size))
	writeMetric(&builder, "canio_runtime_browser_pool_warm", nil, strconv.Itoa(status.BrowserPool.Warm))
	writeMetric(&builder, "canio_runtime_browser_pool_busy", nil, strconv.Itoa(status.BrowserPool.Busy))
	writeMetric(&builder, "canio_runtime_browser_pool_starting", nil, strconv.Itoa(status.BrowserPool.Starting))
	writeMetric(&builder, "canio_runtime_browser_pool_waiting", nil, strconv.Itoa(status.BrowserPool.Waiting))
	writeMetric(&builder, "canio_runtime_browser_pool_queue_limit", nil, strconv.Itoa(status.BrowserPool.QueueLimit))
	writeHelp(&builder, "canio_runtime_worker_pool_size", "Configured async worker pool capacity.")
	writeMetric(&builder, "canio_runtime_worker_pool_size", nil, strconv.Itoa(status.WorkerPool.Size))
	writeMetric(&builder, "canio_runtime_worker_pool_warm", nil, strconv.Itoa(status.WorkerPool.Warm))
	writeMetric(&builder, "canio_runtime_worker_pool_busy", nil, strconv.Itoa(status.WorkerPool.Busy))
	writeMetric(&builder, "canio_runtime_worker_pool_queue_limit", nil, strconv.Itoa(status.WorkerPool.QueueLimit))

	writeHelp(&builder, "canio_http_requests_total", "Count of HTTP requests served by Stagehand.")
	writeHelp(&builder, "canio_http_request_duration_seconds_sum", "Total HTTP request duration by method, route, and status.")
	writeHelp(&builder, "canio_http_request_duration_seconds_count", "Total HTTP request observations by method, route, and status.")

	requestKeys := make([]requestKey, 0, len(requests))
	for key := range requests {
		requestKeys = append(requestKeys, key)
	}
	sort.Slice(requestKeys, func(left int, right int) bool {
		if requestKeys[left].Route != requestKeys[right].Route {
			return requestKeys[left].Route < requestKeys[right].Route
		}
		if requestKeys[left].Method != requestKeys[right].Method {
			return requestKeys[left].Method < requestKeys[right].Method
		}
		return requestKeys[left].Status < requestKeys[right].Status
	})

	for _, key := range requestKeys {
		metric := requests[key]
		labels := map[string]string{
			"method": key.Method,
			"route":  key.Route,
			"status": strconv.Itoa(key.Status),
		}
		writeMetric(&builder, "canio_http_requests_total", labels, strconv.FormatUint(metric.Count, 10))
		writeMetric(&builder, "canio_http_request_duration_seconds_sum", labels, strconv.FormatFloat(metric.DurationSum, 'f', 6, 64))
		writeMetric(&builder, "canio_http_request_duration_seconds_count", labels, strconv.FormatUint(metric.Count, 10))
	}

	writeHelp(&builder, "canio_renders_total", "Total render executions grouped by final status.")
	renderStatuses := sortedKeys(renders)
	for _, renderStatus := range renderStatuses {
		writeMetric(&builder, "canio_renders_total", map[string]string{"status": renderStatus}, strconv.FormatUint(renders[renderStatus], 10))
	}
	writeHelp(&builder, "canio_render_duration_seconds_sum", "Total duration of render executions.")
	writeMetric(&builder, "canio_render_duration_seconds_sum", nil, strconv.FormatFloat(renderDurationSum, 'f', 6, 64))
	writeHelp(&builder, "canio_render_duration_seconds_count", "Total render duration observations.")
	writeMetric(&builder, "canio_render_duration_seconds_count", nil, strconv.FormatUint(renderDurationCount, 10))

	writeHelp(&builder, "canio_job_events_total", "Total async job lifecycle events emitted by Stagehand.")
	for _, kind := range sortedKeys(jobEvents) {
		writeMetric(&builder, "canio_job_events_total", map[string]string{"kind": kind}, strconv.FormatUint(jobEvents[kind], 10))
	}

	writeHelp(&builder, "canio_webhook_deliveries_total", "Total webhook push delivery attempts grouped by final result.")
	for _, result := range sortedKeys(webhooks) {
		writeMetric(&builder, "canio_webhook_deliveries_total", map[string]string{"status": result}, strconv.FormatUint(webhooks[result], 10))
	}

	return builder.String()
}

func normalizeMetricRoute(route string) string {
	route = strings.TrimSpace(route)
	if route == "" {
		return "unknown"
	}

	return route
}

func sortedKeys(values map[string]uint64) []string {
	keys := make([]string, 0, len(values))
	for key := range values {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}

func writeHelp(builder *strings.Builder, name string, help string) {
	fmt.Fprintf(builder, "# HELP %s %s\n", name, help)
	fmt.Fprintf(builder, "# TYPE %s gauge\n", name)
}

func writeMetric(builder *strings.Builder, name string, labels map[string]string, value string) {
	builder.WriteString(name)
	if len(labels) > 0 {
		keys := make([]string, 0, len(labels))
		for key := range labels {
			keys = append(keys, key)
		}
		sort.Strings(keys)

		builder.WriteString("{")
		for index, key := range keys {
			if index > 0 {
				builder.WriteString(",")
			}

			fmt.Fprintf(builder, `%s="%s"`, key, escapeLabel(labels[key]))
		}
		builder.WriteString("}")
	}

	builder.WriteString(" ")
	builder.WriteString(value)
	builder.WriteString("\n")
}

func escapeLabel(value string) string {
	value = strings.ReplaceAll(value, `\`, `\\`)
	value = strings.ReplaceAll(value, `"`, `\"`)
	value = strings.ReplaceAll(value, "\n", `\n`)
	return value
}
