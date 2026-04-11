package auth

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"strings"
	"time"
)

const (
	DefaultAlgorithm       = "canio-v1"
	DefaultTimestampHeader = "X-Canio-Timestamp"
	DefaultSignatureHeader = "X-Canio-Signature"
	DefaultMaxSkew         = 5 * time.Minute
)

var (
	ErrMissingSecret        = errors.New("auth secret is required")
	ErrMissingTimestamp     = errors.New("auth timestamp is required")
	ErrMissingSignature     = errors.New("auth signature is required")
	ErrInvalidAlgorithm     = errors.New("auth algorithm is invalid")
	ErrTimestampOutsideSkew = errors.New("auth timestamp is outside the allowed skew")
	ErrSignatureMismatch    = errors.New("auth signature mismatch")
)

type Config struct {
	Secret          string
	Algorithm       string
	TimestampHeader string
	SignatureHeader string
	MaxSkew         time.Duration
}

type Request struct {
	Method    string
	Path      string
	Body      []byte
	Timestamp time.Time
}

func DefaultConfig(secret string) Config {
	return Config{
		Secret:          secret,
		Algorithm:       DefaultAlgorithm,
		TimestampHeader: DefaultTimestampHeader,
		SignatureHeader: DefaultSignatureHeader,
		MaxSkew:         DefaultMaxSkew,
	}
}

func (c Config) normalize() Config {
	if strings.TrimSpace(c.Algorithm) == "" {
		c.Algorithm = DefaultAlgorithm
	}

	if strings.TrimSpace(c.TimestampHeader) == "" {
		c.TimestampHeader = DefaultTimestampHeader
	}

	if strings.TrimSpace(c.SignatureHeader) == "" {
		c.SignatureHeader = DefaultSignatureHeader
	}

	if c.MaxSkew <= 0 {
		c.MaxSkew = DefaultMaxSkew
	}

	return c
}

func Sign(cfg Config, req Request) (map[string]string, error) {
	cfg = cfg.normalize()

	if strings.TrimSpace(cfg.Secret) == "" {
		return nil, ErrMissingSecret
	}

	if strings.TrimSpace(req.Method) == "" || strings.TrimSpace(req.Path) == "" {
		return nil, fmt.Errorf("auth request method and path are required")
	}

	if req.Timestamp.IsZero() {
		req.Timestamp = time.Now().UTC()
	}

	timestamp := req.Timestamp.UTC().Format(time.RFC3339)
	signature := computeSignature(cfg, req.Method, req.Path, req.Body, timestamp)

	return map[string]string{
		cfg.TimestampHeader: timestamp,
		cfg.SignatureHeader: cfg.Algorithm + "=" + signature,
	}, nil
}

func Verify(cfg Config, req Request, timestamp string, signature string, now time.Time) error {
	cfg = cfg.normalize()
	timestamp = strings.TrimSpace(timestamp)
	signature = strings.TrimSpace(signature)

	if strings.TrimSpace(cfg.Secret) == "" {
		return ErrMissingSecret
	}

	if timestamp == "" {
		return ErrMissingTimestamp
	}

	if signature == "" {
		return ErrMissingSignature
	}

	parsed, err := time.Parse(time.RFC3339, timestamp)
	if err != nil {
		return fmt.Errorf("auth timestamp is invalid: %w", err)
	}

	if now.IsZero() {
		now = time.Now().UTC()
	}

	if now.Sub(parsed.UTC()) > cfg.MaxSkew || parsed.UTC().Sub(now) > cfg.MaxSkew {
		return ErrTimestampOutsideSkew
	}

	prefix := cfg.Algorithm + "="
	if !strings.HasPrefix(signature, prefix) {
		return ErrInvalidAlgorithm
	}

	expected := prefix + computeSignature(cfg, req.Method, req.Path, req.Body, timestamp)
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return ErrSignatureMismatch
	}

	return nil
}

func VerifyAny(configs []Config, req Request, timestamp string, signature string, now time.Time) error {
	if len(configs) == 0 {
		return ErrMissingSecret
	}

	var lastErr error
	for _, cfg := range configs {
		if strings.TrimSpace(cfg.Secret) == "" {
			continue
		}

		if err := Verify(cfg, req, timestamp, signature, now); err == nil {
			return nil
		} else {
			lastErr = err
		}
	}

	if lastErr != nil {
		return lastErr
	}

	return ErrMissingSecret
}

func computeSignature(cfg Config, method string, path string, body []byte, timestamp string) string {
	canonical := strings.Join([]string{
		strings.ToUpper(strings.TrimSpace(method)),
		strings.TrimSpace(path),
		timestamp,
		bodyDigest(body),
	}, "\n")

	mac := hmac.New(sha256.New, []byte(cfg.Secret))
	_, _ = mac.Write([]byte(canonical))
	return hex.EncodeToString(mac.Sum(nil))
}

func bodyDigest(body []byte) string {
	sum := sha256.Sum256(body)
	return hex.EncodeToString(sum[:])
}
