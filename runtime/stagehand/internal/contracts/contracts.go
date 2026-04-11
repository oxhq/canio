package contracts

import (
	"encoding/json"
	"io"
	"time"
)

const (
	RenderSpecContractVersion        = "canio.stagehand.render-spec.v1"
	RenderResultContractVersion      = "canio.stagehand.render-result.v1"
	RenderJobContractVersion         = "canio.stagehand.job.v1"
	RenderJobListContractVersion     = "canio.stagehand.jobs.v1"
	ArtifactContractVersion          = "canio.stagehand.artifact.v1"
	ArtifactListContractVersion      = "canio.stagehand.artifacts.v1"
	RuntimeStatusContractVersion     = "canio.stagehand.runtime-status.v1"
	DeadLetterListContractVersion    = "canio.stagehand.dead-letters.v1"
	DeadLetterCleanupContractVersion = "canio.stagehand.dead-letter-cleanup.v1"
	RuntimeCleanupContractVersion    = "canio.stagehand.runtime-cleanup.v1"
)

type RenderSpec struct {
	ContractVersion string            `json:"contractVersion"`
	RequestID       string            `json:"requestId"`
	Source          RenderSource      `json:"source"`
	Profile         string            `json:"profile"`
	Presentation    map[string]any    `json:"presentation,omitempty"`
	Document        DocumentOptions   `json:"document,omitempty"`
	Execution       map[string]any    `json:"execution,omitempty"`
	Postprocess     map[string]any    `json:"postprocess,omitempty"`
	Debug           map[string]any    `json:"debug,omitempty"`
	Queue           map[string]any    `json:"queue,omitempty"`
	Output          map[string]any    `json:"output,omitempty"`
	Correlation     map[string]string `json:"correlation,omitempty"`
}

type RenderSource struct {
	Type    string         `json:"type"`
	Payload map[string]any `json:"payload"`
}

type DocumentOptions struct {
	Title  string         `json:"title,omitempty"`
	Meta   map[string]any `json:"meta,omitempty"`
	Tagged bool           `json:"tagged,omitempty"`
	Locale string         `json:"locale,omitempty"`
}

type RenderResult struct {
	ContractVersion string           `json:"contractVersion"`
	RequestID       string           `json:"requestId"`
	JobID           string           `json:"jobId"`
	Status          string           `json:"status"`
	Warnings        []string         `json:"warnings,omitempty"`
	Timings         map[string]int64 `json:"timings,omitempty"`
	PDF             RenderedPDF      `json:"pdf"`
	Artifacts       *ArtifactBundle  `json:"artifacts,omitempty"`
}

type RenderedPDF struct {
	Base64      string `json:"base64"`
	ContentType string `json:"contentType"`
	FileName    string `json:"fileName"`
	Bytes       int    `json:"bytes"`
}

type RenderJob struct {
	ContractVersion string            `json:"contractVersion"`
	ID              string            `json:"id"`
	RequestID       string            `json:"requestId"`
	Status          string            `json:"status"`
	Error           string            `json:"error,omitempty"`
	Attempts        int               `json:"attempts"`
	MaxRetries      int               `json:"maxRetries,omitempty"`
	SubmittedAt     string            `json:"submittedAt"`
	StartedAt       string            `json:"startedAt,omitempty"`
	CompletedAt     string            `json:"completedAt,omitempty"`
	NextRetryAt     string            `json:"nextRetryAt,omitempty"`
	DeadLetter      *DeadLetterBundle `json:"deadLetter,omitempty"`
	Result          *RenderResult     `json:"result,omitempty"`
}

type RenderJobList struct {
	ContractVersion string      `json:"contractVersion"`
	Count           int         `json:"count"`
	Items           []RenderJob `json:"items"`
}

type RuntimeStatus struct {
	ContractVersion string      `json:"contractVersion"`
	Version         string      `json:"version"`
	Runtime         RuntimeMeta `json:"runtime"`
	Queue           QueueMeta   `json:"queue"`
	BrowserPool     PoolMeta    `json:"browserPool"`
	WorkerPool      PoolMeta    `json:"workerPool"`
	Control         ControlMeta `json:"control"`
	Time            string      `json:"time"`
}

type RuntimeMeta struct {
	State       string `json:"state"`
	StartedAt   string `json:"startedAt"`
	RenderCount int    `json:"renderCount"`
}

type QueueMeta struct {
	Depth int `json:"depth"`
}

type PoolMeta struct {
	Size       int `json:"size"`
	Warm       int `json:"warm"`
	Busy       int `json:"busy,omitempty"`
	Starting   int `json:"starting,omitempty"`
	Waiting    int `json:"waiting,omitempty"`
	QueueLimit int `json:"queueLimit,omitempty"`
}

type ControlMeta struct {
	AcceptingWork bool                `json:"acceptingWork"`
	ActiveRenders int                 `json:"activeRenders"`
	Maintenance   MaintenanceMeta     `json:"maintenance"`
	Credentials   CredentialStateMeta `json:"credentials"`
}

type MaintenanceMeta struct {
	Mode            string `json:"mode"`
	Note            string `json:"note,omitempty"`
	DrainUntilEmpty bool   `json:"drainUntilEmpty,omitempty"`
	WindowStart     string `json:"windowStart,omitempty"`
	WindowEnd       string `json:"windowEnd,omitempty"`
	Active          bool   `json:"active"`
}

type CredentialStateMeta struct {
	Version                int    `json:"version"`
	Label                  string `json:"label,omitempty"`
	PreviousSecretAccepted bool   `json:"previousSecretAccepted,omitempty"`
	PreviousSecretExpires  string `json:"previousSecretExpires,omitempty"`
}

type ErrorResponse struct {
	Error string `json:"error"`
}

type RuntimeMaintenanceRequest struct {
	Mode            string `json:"mode"`
	Note            string `json:"note,omitempty"`
	DrainUntilEmpty bool   `json:"drainUntilEmpty,omitempty"`
	WindowStart     string `json:"windowStart,omitempty"`
	WindowEnd       string `json:"windowEnd,omitempty"`
}

type RuntimeCredentialRotationRequest struct {
	Secret       string `json:"secret"`
	Label        string `json:"label,omitempty"`
	Version      int    `json:"version,omitempty"`
	GraceSeconds int    `json:"graceSeconds,omitempty"`
}

type ArtifactBundle struct {
	ID        string            `json:"id"`
	ReplayOf  string            `json:"replayOf,omitempty"`
	Directory string            `json:"directory"`
	Files     map[string]string `json:"files"`
}

type ArtifactOutput struct {
	Bytes    int    `json:"bytes"`
	FileName string `json:"fileName"`
}

type ArtifactDebug struct {
	ScreenshotFile string `json:"screenshotFile,omitempty"`
	DOMSnapshot    string `json:"domSnapshot,omitempty"`
	ConsoleLogFile string `json:"consoleLogFile,omitempty"`
	NetworkLogFile string `json:"networkLogFile,omitempty"`
	ConsoleEvents  int    `json:"consoleEvents,omitempty"`
	NetworkEvents  int    `json:"networkEvents,omitempty"`
}

type ArtifactDetail struct {
	ContractVersion string            `json:"contractVersion"`
	ID              string            `json:"id"`
	ReplayOf        string            `json:"replayOf,omitempty"`
	RequestID       string            `json:"requestId"`
	Profile         string            `json:"profile,omitempty"`
	SourceType      string            `json:"sourceType"`
	Status          string            `json:"status"`
	CreatedAt       string            `json:"createdAt"`
	Warnings        []string          `json:"warnings,omitempty"`
	Timings         map[string]int64  `json:"timings,omitempty"`
	Output          ArtifactOutput    `json:"output"`
	Debug           ArtifactDebug     `json:"debug,omitempty"`
	Directory       string            `json:"directory"`
	Files           map[string]string `json:"files"`
}

type ArtifactList struct {
	ContractVersion string           `json:"contractVersion"`
	Count           int              `json:"count"`
	Items           []ArtifactDetail `json:"items"`
}

type DeadLetterBundle struct {
	ID        string            `json:"id"`
	Directory string            `json:"directory"`
	Files     map[string]string `json:"files"`
}

type ReplayRequest struct {
	ArtifactID string `json:"artifactId"`
}

type DeadLetterEntry struct {
	ID         string            `json:"id"`
	JobID      string            `json:"jobId"`
	RequestID  string            `json:"requestId"`
	Attempts   int               `json:"attempts"`
	MaxRetries int               `json:"maxRetries,omitempty"`
	FailedAt   string            `json:"failedAt,omitempty"`
	Error      string            `json:"error,omitempty"`
	Directory  string            `json:"directory"`
	Files      map[string]string `json:"files"`
}

type DeadLetterList struct {
	ContractVersion string            `json:"contractVersion"`
	Count           int               `json:"count"`
	Items           []DeadLetterEntry `json:"items"`
}

type DeadLetterCleanup struct {
	ContractVersion string            `json:"contractVersion"`
	Count           int               `json:"count"`
	Removed         []DeadLetterEntry `json:"removed"`
}

type DeadLetterRequeueRequest struct {
	DeadLetterID string `json:"deadLetterId"`
}

type DeadLetterCleanupRequest struct {
	OlderThanDays int `json:"olderThanDays,omitempty"`
}

type RuntimeCleanupRequest struct {
	JobsOlderThanDays        int `json:"jobsOlderThanDays,omitempty"`
	ArtifactsOlderThanDays   int `json:"artifactsOlderThanDays,omitempty"`
	DeadLettersOlderThanDays int `json:"deadLettersOlderThanDays,omitempty"`
}

type RuntimeCleanupEntry struct {
	ID        string `json:"id"`
	Directory string `json:"directory"`
}

type RuntimeCleanupGroup struct {
	Count   int                   `json:"count"`
	Removed []RuntimeCleanupEntry `json:"removed,omitempty"`
}

type RuntimeCleanup struct {
	ContractVersion string              `json:"contractVersion"`
	Jobs            RuntimeCleanupGroup `json:"jobs"`
	Artifacts       RuntimeCleanupGroup `json:"artifacts"`
	DeadLetters     RuntimeCleanupGroup `json:"deadLetters"`
}

func DecodeRenderSpec(r io.Reader) (RenderSpec, error) {
	var spec RenderSpec
	err := json.NewDecoder(r).Decode(&spec)
	return spec, err
}

func DecodeReplayRequest(r io.Reader) (ReplayRequest, error) {
	var request ReplayRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func DecodeDeadLetterRequeueRequest(r io.Reader) (DeadLetterRequeueRequest, error) {
	var request DeadLetterRequeueRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func DecodeDeadLetterCleanupRequest(r io.Reader) (DeadLetterCleanupRequest, error) {
	var request DeadLetterCleanupRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func DecodeRuntimeCleanupRequest(r io.Reader) (RuntimeCleanupRequest, error) {
	var request RuntimeCleanupRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func DecodeRuntimeMaintenanceRequest(r io.Reader) (RuntimeMaintenanceRequest, error) {
	var request RuntimeMaintenanceRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func DecodeRuntimeCredentialRotationRequest(r io.Reader) (RuntimeCredentialRotationRequest, error) {
	var request RuntimeCredentialRotationRequest
	err := json.NewDecoder(r).Decode(&request)
	return request, err
}

func EncodeJSON(w io.Writer, value any) error {
	encoder := json.NewEncoder(w)
	encoder.SetEscapeHTML(false)
	encoder.SetIndent("", "  ")
	return encoder.Encode(value)
}

func NewRuntimeStatus(version string, startedAt time.Time, renderCount int, state string) RuntimeStatus {
	return RuntimeStatus{
		ContractVersion: RuntimeStatusContractVersion,
		Version:         version,
		Runtime: RuntimeMeta{
			State:       state,
			StartedAt:   startedAt.UTC().Format(time.RFC3339),
			RenderCount: renderCount,
		},
		Queue: QueueMeta{
			Depth: 0,
		},
		BrowserPool: PoolMeta{
			Size: 0,
			Warm: 0,
		},
		WorkerPool: PoolMeta{
			Size: 0,
			Warm: 0,
		},
		Control: ControlMeta{
			AcceptingWork: true,
			ActiveRenders: 0,
			Maintenance: MaintenanceMeta{
				Mode:   "ready",
				Active: false,
			},
			Credentials: CredentialStateMeta{
				Version: 0,
			},
		},
		Time: time.Now().UTC().Format(time.RFC3339),
	}
}
