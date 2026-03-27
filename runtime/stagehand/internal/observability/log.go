package observability

import (
	"encoding/json"
	"fmt"
	"log"
	"sort"
	"strings"
	"sync/atomic"
	"time"
)

var logFormat atomic.Value

func init() {
	logFormat.Store("json")
}

func SetLogFormat(format string) {
	format = strings.ToLower(strings.TrimSpace(format))
	if format != "text" {
		format = "json"
	}

	logFormat.Store(format)
}

func Info(event string, fields map[string]any) {
	write("info", event, fields)
}

func Error(event string, err error, fields map[string]any) {
	if err != nil {
		fields = cloneFields(fields)
		fields["error"] = err.Error()
	}

	write("error", event, fields)
}

func write(level string, event string, fields map[string]any) {
	payload := map[string]any{
		"ts":    time.Now().UTC().Format(time.RFC3339Nano),
		"level": strings.ToLower(strings.TrimSpace(level)),
		"event": strings.TrimSpace(event),
	}

	for key, value := range fields {
		if strings.TrimSpace(key) == "" {
			continue
		}

		payload[key] = value
	}

	if configured, _ := logFormat.Load().(string); configured == "text" {
		log.Print(formatText(payload))
		return
	}

	encoded, err := json.Marshal(payload)
	if err != nil {
		log.Print(formatText(payload))
		return
	}

	log.Print(string(encoded))
}

func formatText(payload map[string]any) string {
	keys := make([]string, 0, len(payload))
	for key := range payload {
		keys = append(keys, key)
	}
	sort.Strings(keys)

	parts := make([]string, 0, len(keys))
	for _, key := range keys {
		parts = append(parts, fmt.Sprintf("%s=%v", key, payload[key]))
	}

	return strings.Join(parts, " ")
}

func cloneFields(fields map[string]any) map[string]any {
	if len(fields) == 0 {
		return map[string]any{}
	}

	cloned := make(map[string]any, len(fields))
	for key, value := range fields {
		cloned[key] = value
	}

	return cloned
}
