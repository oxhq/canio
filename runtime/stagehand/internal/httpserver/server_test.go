package httpserver

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	appruntime "github.com/oxhq/canio/runtime/stagehand/internal/app"
	stageauth "github.com/oxhq/canio/runtime/stagehand/internal/auth"
	"github.com/oxhq/canio/runtime/stagehand/internal/config"
)

func TestMetricsEndpointExposesPrometheusPayload(t *testing.T) {
	app := appruntime.New(config.Default())
	defer app.Close()

	handler := New(app)

	healthRequest := httptest.NewRequest(http.MethodGet, "/healthz", nil)
	healthResponse := httptest.NewRecorder()
	handler.ServeHTTP(healthResponse, healthRequest)

	statusRequest := httptest.NewRequest(http.MethodGet, "/v1/runtime/status", nil)
	statusResponse := httptest.NewRecorder()
	handler.ServeHTTP(statusResponse, statusRequest)

	metricsRequest := httptest.NewRequest(http.MethodGet, "/metrics", nil)
	metricsResponse := httptest.NewRecorder()
	handler.ServeHTTP(metricsResponse, metricsRequest)

	if metricsResponse.Code != http.StatusOK {
		t.Fatalf("expected metrics endpoint to return 200, got %d", metricsResponse.Code)
	}

	body := metricsResponse.Body.String()
	required := []string{
		"canio_runtime_up 1",
		`canio_http_requests_total{method="GET",route="/healthz",status="200"} 1`,
		`canio_http_requests_total{method="GET",route="/v1/runtime/status",status="200"} 1`,
		"canio_runtime_queue_depth",
	}

	for _, fragment := range required {
		if !strings.Contains(body, fragment) {
			t.Fatalf("metrics payload missing %q\n%s", fragment, body)
		}
	}
}

func TestRenderEndpointReturnsDecodeDetailsForInvalidJSON(t *testing.T) {
	app := appruntime.New(config.Default())
	defer app.Close()

	handler := New(app)
	request := httptest.NewRequest(http.MethodPost, "/v1/renders", strings.NewReader(`{"postprocess":[]}`))
	response := httptest.NewRecorder()

	handler.ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest {
		t.Fatalf("expected renders endpoint to return 400, got %d", response.Code)
	}

	body := response.Body.String()
	if !strings.Contains(body, "invalid render spec JSON") {
		t.Fatalf("expected invalid render spec message, got %s", body)
	}

	if !strings.Contains(body, "cannot unmarshal array into Go struct field") {
		t.Fatalf("expected decode detail in response, got %s", body)
	}
}

func TestRuntimeMaintenanceBlocksNewWork(t *testing.T) {
	app := appruntime.New(config.Default())
	defer app.Close()

	handler := New(app)

	maintenance := httptest.NewRequest(http.MethodPost, "/v1/runtime/maintenance", strings.NewReader(`{"mode":"draining","note":"patch window","drainUntilEmpty":true}`))
	maintenance.Header.Set("Content-Type", "application/json")
	maintenanceResponse := httptest.NewRecorder()
	handler.ServeHTTP(maintenanceResponse, maintenance)

	if maintenanceResponse.Code != http.StatusOK {
		t.Fatalf("expected maintenance endpoint to return 200, got %d", maintenanceResponse.Code)
	}

	renderRequest := httptest.NewRequest(http.MethodPost, "/v1/renders", strings.NewReader(`{}`))
	renderResponse := httptest.NewRecorder()
	handler.ServeHTTP(renderResponse, renderRequest)

	if renderResponse.Code != http.StatusServiceUnavailable {
		t.Fatalf("expected renders endpoint to return 503 during maintenance, got %d", renderResponse.Code)
	}

	if !strings.Contains(renderResponse.Body.String(), "runtime is not accepting new work") {
		t.Fatalf("expected maintenance error message, got %s", renderResponse.Body.String())
	}
}

func TestRuntimeCredentialRotationAcceptsNewSecretWithGraceWindow(t *testing.T) {
	cfg := config.Default()
	cfg.AuthSharedSecret = "current-secret"
	app := appruntime.New(cfg)
	defer app.Close()

	handler := New(app)
	rotateBody := `{"secret":"next-secret","label":"runtime-v2","version":2,"graceSeconds":600}`
	rotateRequest := httptest.NewRequest(http.MethodPost, "/v1/runtime/credentials/rotate", strings.NewReader(rotateBody))
	rotateRequest.Header.Set("Content-Type", "application/json")
	setSignedHeaders(t, app, rotateRequest, http.MethodPost, "/v1/runtime/credentials/rotate", []byte(rotateBody))
	rotateResponse := httptest.NewRecorder()
	handler.ServeHTTP(rotateResponse, rotateRequest)

	if rotateResponse.Code != http.StatusOK {
		t.Fatalf("expected rotation endpoint to return 200, got %d", rotateResponse.Code)
	}

	oldSignedStatus := httptest.NewRequest(http.MethodGet, "/v1/runtime/status", nil)
	setSignedHeadersWithSecret(t, oldSignedStatus, http.MethodGet, "/v1/runtime/status", nil, "current-secret")
	oldResponse := httptest.NewRecorder()
	handler.ServeHTTP(oldResponse, oldSignedStatus)
	if oldResponse.Code != http.StatusOK {
		t.Fatalf("expected previous secret to remain accepted during grace window, got %d", oldResponse.Code)
	}

	newSignedStatus := httptest.NewRequest(http.MethodGet, "/v1/runtime/status", nil)
	setSignedHeadersWithSecret(t, newSignedStatus, http.MethodGet, "/v1/runtime/status", nil, "next-secret")
	newResponse := httptest.NewRecorder()
	handler.ServeHTTP(newResponse, newSignedStatus)
	if newResponse.Code != http.StatusOK {
		t.Fatalf("expected new secret to be accepted after rotation, got %d", newResponse.Code)
	}
}

func setSignedHeaders(t *testing.T, app *appruntime.App, request *http.Request, method string, path string, body []byte) {
	t.Helper()
	setSignedHeadersWithSecret(t, request, method, path, body, app.AuthConfig().Secret)
}

func setSignedHeadersWithSecret(t *testing.T, request *http.Request, method string, path string, body []byte, secret string) {
	t.Helper()
	headers, err := stageauth.Sign(stageauth.DefaultConfig(secret), stageauth.Request{
		Method: method,
		Path:   path,
		Body:   body,
	})
	if err != nil {
		t.Fatalf("failed to sign request: %v", err)
	}

	for key, value := range headers {
		request.Header.Set(key, value)
	}
}
