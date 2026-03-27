package httpserver

import (
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	appruntime "github.com/oxhq/canio/runtime/stagehand/internal/app"
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
