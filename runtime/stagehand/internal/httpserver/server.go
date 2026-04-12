package httpserver

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"strconv"
	"strings"
	"time"

	appruntime "github.com/oxhq/canio/runtime/stagehand/internal/app"
	"github.com/oxhq/canio/runtime/stagehand/internal/artifacts"
	stageauth "github.com/oxhq/canio/runtime/stagehand/internal/auth"
	"github.com/oxhq/canio/runtime/stagehand/internal/browser"
	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
	"github.com/oxhq/canio/runtime/stagehand/internal/jobs"
	"github.com/oxhq/canio/runtime/stagehand/internal/observability"
)

func New(app *appruntime.App) http.Handler {
	mux := http.NewServeMux()

	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		w.Header().Set("Content-Type", "text/plain; charset=utf-8")
		_, _ = w.Write([]byte("ok\n"))
	})

	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		_, _ = io.WriteString(w, app.Metrics())
	})

	mux.HandleFunc("/v1/runtime/status", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		writeJSON(w, http.StatusOK, app.Status())
	})

	mux.HandleFunc("/v1/runtime/restart", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		writeJSON(w, http.StatusOK, app.Restart())
	})

	mux.HandleFunc("/v1/runtime/cleanup", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeRuntimeCleanupRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid runtime cleanup request JSON")
			return
		}

		result, err := app.RuntimeCleanup(request)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, result)
	})

	mux.HandleFunc("/v1/runtime/maintenance", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeRuntimeMaintenanceRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid runtime maintenance request JSON")
			return
		}

		writeJSON(w, http.StatusOK, app.UpdateRuntimeMaintenance(request))
	})

	mux.HandleFunc("/v1/runtime/credentials/rotate", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeRuntimeCredentialRotationRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid runtime credential rotation request JSON")
			return
		}

		status, err := app.RotateRuntimeCredentials(request)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, status)
	})

	mux.HandleFunc("/v1/renders", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		spec, err := contracts.DecodeRenderSpec(r.Body)
		if err != nil {
			writeDecodeError(w, err, fmt.Sprintf("invalid render spec JSON: %v", err))
			return
		}

		result, err := app.Render(r.Context(), spec)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, result)
	})

	mux.HandleFunc("/v1/jobs", func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case http.MethodGet:
			writeJSON(w, http.StatusOK, app.Jobs(parseLimit(r, 20)))
		case http.MethodPost:
			spec, err := contracts.DecodeRenderSpec(r.Body)
			if err != nil {
				writeDecodeError(w, err, fmt.Sprintf("invalid render spec JSON: %v", err))
				return
			}

			job, err := app.Dispatch(r.Context(), spec)
			if err != nil {
				writeAppError(w, err)
				return
			}

			writeJSON(w, http.StatusAccepted, job)
		default:
			writeMethodNotAllowed(w)
		}
	})

	mux.HandleFunc("/v1/jobs/", func(w http.ResponseWriter, r *http.Request) {
		path := strings.TrimPrefix(r.URL.Path, "/v1/jobs/")
		if strings.HasSuffix(path, "/events") {
			if r.Method != http.MethodGet {
				writeMethodNotAllowed(w)
				return
			}

			jobID := strings.TrimSuffix(path, "/events")
			streamJobEvents(w, r, app, jobID)
			return
		}

		if strings.HasSuffix(path, "/cancel") {
			if r.Method != http.MethodPost {
				writeMethodNotAllowed(w)
				return
			}

			jobID := strings.TrimSuffix(path, "/cancel")
			job, err := app.CancelJob(jobID)
			if err != nil {
				writeAppError(w, err)
				return
			}

			writeJSON(w, http.StatusAccepted, job)
			return
		}

		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		job, err := app.Job(path)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, job)
	})

	mux.HandleFunc("/v1/artifacts/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		artifact, err := app.Artifact(strings.TrimPrefix(r.URL.Path, "/v1/artifacts/"))
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, artifact)
	})

	mux.HandleFunc("/v1/artifacts", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		artifactsList, err := app.Artifacts(parseLimit(r, 20))
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, artifactsList)
	})

	mux.HandleFunc("/v1/dead-letters", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			writeMethodNotAllowed(w)
			return
		}

		payload, err := app.DeadLetters()
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, payload)
	})

	mux.HandleFunc("/v1/dead-letters/requeues", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeDeadLetterRequeueRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid dead-letter requeue request JSON")
			return
		}

		job, err := app.RequeueDeadLetter(r.Context(), request.DeadLetterID)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusAccepted, job)
	})

	mux.HandleFunc("/v1/dead-letters/cleanup", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeDeadLetterCleanupRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid dead-letter cleanup request JSON")
			return
		}

		result, err := app.CleanupDeadLetters(request.OlderThanDays)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, result)
	})

	mux.HandleFunc("/v1/replays", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeMethodNotAllowed(w)
			return
		}

		request, err := contracts.DecodeReplayRequest(r.Body)
		if err != nil {
			writeDecodeError(w, err, "invalid replay request JSON")
			return
		}

		result, err := app.Replay(r.Context(), request.ArtifactID)
		if err != nil {
			writeAppError(w, err)
			return
		}

		writeJSON(w, http.StatusOK, result)
	})

	return withObservability(app, withRequestBodyLimit(app, withAuth(app, mux)))
}

func withRequestBodyLimit(app *appruntime.App, next http.Handler) http.Handler {
	limit := app.RequestBodyLimitBytes()
	if limit <= 0 {
		return next
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if !strings.HasPrefix(r.URL.Path, "/v1/") || !requestCanHaveBody(r.Method) {
			next.ServeHTTP(w, r)
			return
		}

		r.Body = http.MaxBytesReader(w, r.Body, limit)
		next.ServeHTTP(w, r)
	})
}

func withAuth(app *appruntime.App, next http.Handler) http.Handler {
	authConfig := app.AuthConfig()
	if strings.TrimSpace(authConfig.Secret) == "" {
		return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path == "/healthz" || r.URL.Path == "/metrics" || !strings.HasPrefix(r.URL.Path, "/v1/") || requestIsLoopback(r.RemoteAddr) {
				next.ServeHTTP(w, r)
				return
			}

			writeJSON(w, http.StatusUnauthorized, contracts.ErrorResponse{Error: "unsigned Stagehand requests are only accepted from loopback clients when auth is unset"})
		})
	}

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/healthz" || r.URL.Path == "/metrics" || !strings.HasPrefix(r.URL.Path, "/v1/") {
			next.ServeHTTP(w, r)
			return
		}

		body, err := io.ReadAll(r.Body)
		if err != nil {
			if isRequestBodyTooLarge(err) {
				writeJSON(w, http.StatusRequestEntityTooLarge, contracts.ErrorResponse{Error: "request body exceeds the Stagehand limit"})
				return
			}

			writeJSON(w, http.StatusBadRequest, contracts.ErrorResponse{Error: "failed to read request body"})
			return
		}
		r.Body = io.NopCloser(bytes.NewReader(body))

		if err := stageauth.VerifyAny(
			app.AuthConfigs(),
			stageauth.Request{
				Method: r.Method,
				Path:   r.URL.Path,
				Body:   body,
			},
			r.Header.Get(authConfig.TimestampHeader),
			r.Header.Get(authConfig.SignatureHeader),
			time.Now().UTC(),
		); err != nil {
			writeJSON(w, http.StatusUnauthorized, contracts.ErrorResponse{Error: err.Error()})
			return
		}

		next.ServeHTTP(w, r)
	})
}

func requestCanHaveBody(method string) bool {
	switch method {
	case http.MethodPost, http.MethodPut, http.MethodPatch:
		return true
	default:
		return false
	}
}

func requestIsLoopback(remoteAddr string) bool {
	host, _, err := net.SplitHostPort(strings.TrimSpace(remoteAddr))
	if err != nil {
		host = strings.TrimSpace(remoteAddr)
	}

	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

func writeDecodeError(w http.ResponseWriter, err error, message string) {
	if isRequestBodyTooLarge(err) {
		writeJSON(w, http.StatusRequestEntityTooLarge, contracts.ErrorResponse{Error: "request body exceeds the Stagehand limit"})
		return
	}

	writeJSON(w, http.StatusBadRequest, contracts.ErrorResponse{Error: message})
}

func isRequestBodyTooLarge(err error) bool {
	if err == nil {
		return false
	}

	var maxBytesErr *http.MaxBytesError
	return errors.As(err, &maxBytesErr) || strings.Contains(err.Error(), "http: request body too large")
}

func withObservability(app *appruntime.App, next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		start := time.Now()
		writer := &statusRecorder{ResponseWriter: w, status: http.StatusOK}

		next.ServeHTTP(writer, r)

		route := routeLabel(r)
		duration := time.Since(start)
		app.RecordHTTPRequest(r.Method, route, writer.status, duration)

		if app.RequestLoggingEnabled() {
			observability.Info("stagehand_http_request", map[string]any{
				"method":      r.Method,
				"route":       route,
				"path":        r.URL.Path,
				"status":      writer.status,
				"duration_ms": duration.Milliseconds(),
				"remote_addr": r.RemoteAddr,
				"user_agent":  r.UserAgent(),
			})
		}
	})
}

type statusRecorder struct {
	http.ResponseWriter
	status int
}

func (r *statusRecorder) WriteHeader(status int) {
	r.status = status
	r.ResponseWriter.WriteHeader(status)
}

func routeLabel(r *http.Request) string {
	path := strings.TrimSpace(r.URL.Path)
	switch {
	case path == "/healthz":
		return "/healthz"
	case path == "/metrics":
		return "/metrics"
	case path == "/v1/runtime/status":
		return "/v1/runtime/status"
	case path == "/v1/runtime/restart":
		return "/v1/runtime/restart"
	case path == "/v1/runtime/cleanup":
		return "/v1/runtime/cleanup"
	case path == "/v1/renders":
		return "/v1/renders"
	case path == "/v1/jobs":
		return "/v1/jobs"
	case strings.HasPrefix(path, "/v1/jobs/") && strings.HasSuffix(path, "/events"):
		return "/v1/jobs/:id/events"
	case strings.HasPrefix(path, "/v1/jobs/") && strings.HasSuffix(path, "/cancel"):
		return "/v1/jobs/:id/cancel"
	case strings.HasPrefix(path, "/v1/jobs/"):
		return "/v1/jobs/:id"
	case path == "/v1/artifacts":
		return "/v1/artifacts"
	case strings.HasPrefix(path, "/v1/artifacts/"):
		return "/v1/artifacts/:id"
	case path == "/v1/dead-letters":
		return "/v1/dead-letters"
	case path == "/v1/dead-letters/requeues":
		return "/v1/dead-letters/requeues"
	case path == "/v1/dead-letters/cleanup":
		return "/v1/dead-letters/cleanup"
	case path == "/v1/replays":
		return "/v1/replays"
	default:
		return path
	}
}

func writeMethodNotAllowed(w http.ResponseWriter) {
	writeJSON(w, http.StatusMethodNotAllowed, contracts.ErrorResponse{Error: "method not allowed"})
}

func writeAppError(w http.ResponseWriter, err error) {
	switch {
	case errors.Is(err, jobs.ErrJobNotFound), errors.Is(err, jobs.ErrDeadLetterNotFound), errors.Is(err, artifacts.ErrArtifactNotFound):
		writeJSON(w, http.StatusNotFound, contracts.ErrorResponse{Error: err.Error()})
	case errors.Is(err, jobs.ErrQueueFull), errors.Is(err, browser.ErrQueueFull):
		writeJSON(w, http.StatusTooManyRequests, contracts.ErrorResponse{Error: err.Error()})
	case errors.Is(err, appruntime.ErrRuntimeMaintenance):
		writeJSON(w, http.StatusServiceUnavailable, contracts.ErrorResponse{Error: err.Error()})
	default:
		writeJSON(w, http.StatusBadRequest, contracts.ErrorResponse{Error: err.Error()})
	}
}

func writeJSON(w http.ResponseWriter, status int, payload any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = contracts.EncodeJSON(w, payload)
}

func streamJobEvents(w http.ResponseWriter, r *http.Request, app *appruntime.App, jobID string) {
	flusher, ok := w.(http.Flusher)
	if !ok {
		writeJSON(w, http.StatusInternalServerError, contracts.ErrorResponse{Error: "streaming is not supported by the current response writer"})
		return
	}

	jobID = strings.TrimSpace(jobID)
	if jobID == "" {
		writeJSON(w, http.StatusBadRequest, contracts.ErrorResponse{Error: "job ID is required"})
		return
	}

	if _, err := app.Job(jobID); err != nil {
		writeAppError(w, err)
		return
	}

	since := parseSinceSequence(r)
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	history := app.JobEventHistory(jobID, since)
	for _, event := range history {
		writeSSEEvent(w, event)
		flusher.Flush()
		since = event.Sequence
		if terminalEvent(event.Kind) {
			return
		}
	}

	subscription := app.SubscribeJobEvents(r.Context(), 32)
	if subscription == nil {
		return
	}
	defer subscription.Close()

	heartbeat := time.NewTicker(15 * time.Second)
	defer heartbeat.Stop()

	for {
		select {
		case <-r.Context().Done():
			return
		case <-heartbeat.C:
			_, _ = w.Write([]byte(": keep-alive\n\n"))
			flusher.Flush()
		case event, ok := <-subscription.Events:
			if !ok || strings.TrimSpace(event.Job.ID) != jobID || event.Sequence <= since {
				if !ok {
					return
				}
				continue
			}

			writeSSEEvent(w, event)
			flusher.Flush()
			since = event.Sequence
			if terminalEvent(event.Kind) {
				return
			}
		}
	}
}

func writeSSEEvent(w io.Writer, event events.JobEvent) {
	payload, err := jsonMarshal(event)
	if err != nil {
		return
	}

	_, _ = io.WriteString(w, "id: "+strconv.FormatUint(event.Sequence, 10)+"\n")
	_, _ = io.WriteString(w, "event: "+string(event.Kind)+"\n")
	_, _ = io.WriteString(w, "data: "+payload+"\n\n")
}

func parseSinceSequence(r *http.Request) uint64 {
	if raw := strings.TrimSpace(r.URL.Query().Get("since")); raw != "" {
		if parsed, err := strconv.ParseUint(raw, 10, 64); err == nil {
			return parsed
		}
	}

	if raw := strings.TrimSpace(r.Header.Get("Last-Event-ID")); raw != "" {
		if parsed, err := strconv.ParseUint(raw, 10, 64); err == nil {
			return parsed
		}
	}

	return 0
}

func terminalEvent(kind events.Kind) bool {
	switch kind {
	case events.JobCompleted, events.JobFailed, events.JobCancelled:
		return true
	default:
		return false
	}
}

func jsonMarshal(value any) (string, error) {
	data, err := json.Marshal(value)
	if err != nil {
		return "", err
	}

	return string(data), nil
}

func parseLimit(r *http.Request, fallback int) int {
	raw := strings.TrimSpace(r.URL.Query().Get("limit"))
	if raw == "" {
		return fallback
	}

	limit, err := strconv.Atoi(raw)
	if err != nil || limit <= 0 {
		return fallback
	}

	return limit
}
