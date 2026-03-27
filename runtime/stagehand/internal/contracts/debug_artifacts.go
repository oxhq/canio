package contracts

type DebugArtifacts struct {
	ScreenshotPNG []byte         `json:"-"`
	DOMSnapshot   string         `json:"-"`
	Console       []ConsoleEvent `json:"console,omitempty"`
	Network       []NetworkEvent `json:"network,omitempty"`
}

type ConsoleEvent struct {
	Timestamp string `json:"timestamp,omitempty"`
	Type      string `json:"type"`
	Message   string `json:"message"`
	URL       string `json:"url,omitempty"`
	Line      int64  `json:"line,omitempty"`
	Column    int64  `json:"column,omitempty"`
}

type NetworkEvent struct {
	Timestamp string `json:"timestamp,omitempty"`
	Stage     string `json:"stage"`
	RequestID string `json:"requestId"`
	URL       string `json:"url,omitempty"`
	Method    string `json:"method,omitempty"`
	Status    int64  `json:"status,omitempty"`
	MimeType  string `json:"mimeType,omitempty"`
	Resource  string `json:"resource,omitempty"`
	Error     string `json:"error,omitempty"`
}

func DebugArtifactsEnabled(spec RenderSpec) bool {
	enabled, _ := spec.Debug["enabled"].(bool)
	watch, _ := spec.Debug["watch"].(bool)
	return enabled || watch
}
