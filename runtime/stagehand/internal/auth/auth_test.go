package auth

import (
	"testing"
	"time"
)

func TestSignAndVerifyRoundTrip(t *testing.T) {
	cfg := DefaultConfig("secret-123")
	ts := time.Date(2026, time.March, 27, 12, 0, 0, 0, time.UTC)
	req := Request{
		Method: "POST",
		Path:   "/v1/jobs",
		Body:   []byte(`{"requestId":"req-123"}`),
	}

	headers, err := Sign(cfg, Request{
		Method:    req.Method,
		Path:      req.Path,
		Body:      req.Body,
		Timestamp: ts,
	})
	if err != nil {
		t.Fatalf("Sign returned error: %v", err)
	}

	if headers[cfg.TimestampHeader] != ts.Format(time.RFC3339) {
		t.Fatalf("timestamp header = %q, want %q", headers[cfg.TimestampHeader], ts.Format(time.RFC3339))
	}

	if err := Verify(cfg, req, headers[cfg.TimestampHeader], headers[cfg.SignatureHeader], ts); err != nil {
		t.Fatalf("Verify returned error: %v", err)
	}
}

func TestVerifyRejectsModifiedBody(t *testing.T) {
	cfg := DefaultConfig("secret-123")
	ts := time.Date(2026, time.March, 27, 12, 0, 0, 0, time.UTC)
	headers, err := Sign(cfg, Request{
		Method:    "POST",
		Path:      "/v1/jobs",
		Body:      []byte(`{"requestId":"req-123"}`),
		Timestamp: ts,
	})
	if err != nil {
		t.Fatalf("Sign returned error: %v", err)
	}

	err = Verify(cfg, Request{
		Method: "POST",
		Path:   "/v1/jobs",
		Body:   []byte(`{"requestId":"req-124"}`),
	}, headers[cfg.TimestampHeader], headers[cfg.SignatureHeader], ts)
	if err == nil {
		t.Fatal("expected Verify to reject modified body")
	}
}

func TestVerifyAcceptsPHPStyleRFC3339Offset(t *testing.T) {
	cfg := DefaultConfig("secret-123")
	timestamp := "2026-03-27T12:00:00+00:00"
	req := Request{
		Method: "GET",
		Path:   "/v1/jobs/job-123",
		Body:   nil,
	}

	signature := cfg.Algorithm + "=" + computeSignature(cfg, req.Method, req.Path, req.Body, timestamp)

	if err := Verify(cfg, req, timestamp, signature, time.Date(2026, time.March, 27, 12, 0, 0, 0, time.UTC)); err != nil {
		t.Fatalf("Verify returned error: %v", err)
	}
}
