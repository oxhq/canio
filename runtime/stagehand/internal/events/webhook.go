package events

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"
)

type WebhookTarget struct {
	URL     string
	Secret  string
	Headers map[string]string
}

type WebhookDelivery struct {
	Request   *http.Request
	Response  *http.Response
	Body      []byte
	Signature string
}

type WebhookDispatcher struct {
	client *http.Client
}

func NewWebhookDispatcher(client *http.Client) *WebhookDispatcher {
	if client == nil {
		client = http.DefaultClient
	}

	return &WebhookDispatcher{client: client}
}

func (d *WebhookDispatcher) Deliver(ctx context.Context, target WebhookTarget, event JobEvent) (*WebhookDelivery, error) {
	if strings.TrimSpace(target.URL) == "" {
		return nil, fmt.Errorf("webhook URL is required")
	}

	body, err := json.Marshal(event)
	if err != nil {
		return nil, err
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, target.URL, bytes.NewReader(body))
	if err != nil {
		return nil, err
	}

	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("X-Canio-Event", string(event.Kind))
	request.Header.Set("X-Canio-Event-Id", event.ID)
	request.Header.Set("X-Canio-Event-Sequence", fmt.Sprintf("%d", event.Sequence))
	request.Header.Set("X-Canio-Event-At", event.EmittedAt)

	for key, value := range target.Headers {
		if strings.TrimSpace(key) == "" || strings.TrimSpace(value) == "" {
			continue
		}

		request.Header.Set(key, value)
	}

	signature := ""
	if secret := strings.TrimSpace(target.Secret); secret != "" {
		timestamp := fmt.Sprintf("%d", time.Now().UTC().Unix())
		request.Header.Set("X-Canio-Delivery-Timestamp", timestamp)
		signature = signWebhookPayload(secret, timestamp, body)
		request.Header.Set("X-Canio-Delivery-Signature", signature)
	}

	response, err := d.client.Do(request)
	if err != nil {
		return nil, err
	}

	return &WebhookDelivery{
		Request:   request,
		Response:  response,
		Body:      body,
		Signature: signature,
	}, nil
}

func signWebhookPayload(secret string, timestamp string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	_, _ = mac.Write([]byte(timestamp))
	_, _ = mac.Write([]byte("."))
	_, _ = mac.Write(body)

	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}
