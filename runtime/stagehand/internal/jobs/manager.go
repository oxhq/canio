package jobs

import (
	"context"
	"crypto/sha1"
	"errors"
	"fmt"
	"os"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/oxhq/canio/runtime/stagehand/internal/contracts"
	"github.com/oxhq/canio/runtime/stagehand/internal/events"
)

var (
	ErrQueueFull          = errors.New("job queue is full")
	ErrJobNotFound        = errors.New("job not found")
	ErrDeadLetterNotFound = errors.New("dead letter not found")
	ErrInvalidStore       = errors.New("job store is not configured")
)

type Runner func(context.Context, contracts.RenderSpec) (contracts.RenderResult, error)

type Stats struct {
	WorkerCount int
	BusyWorkers int
	QueueDepth  int
	QueueLimit  int
}

type Manager struct {
	store  *Store
	runner Runner

	ctx    context.Context
	cancel context.CancelFunc
	wg     sync.WaitGroup

	backend string
	queue   queueBackend

	mu             sync.RWMutex
	records        map[string]Record
	queues         map[string]struct{}
	runningCancels map[string]context.CancelFunc
	busy           int
	workers        int
	jobTTL         time.Duration
	deadLetterTTL  time.Duration
	events         *events.Bus
}

func NewManager(cfg Config, runner Runner) (*Manager, error) {
	if runner == nil {
		return nil, errors.New("jobs runner is required")
	}

	workerCount := normalizeWorkerCount(cfg.Workers)
	queueBackend, err := newQueueBackend(cfg.Queue)
	if err != nil {
		return nil, err
	}

	ctx, cancel := context.WithCancel(context.Background())
	manager := &Manager{
		store:   NewStore(cfg.StateDir),
		runner:  runner,
		ctx:     ctx,
		cancel:  cancel,
		backend: configuredBackendName(cfg.Queue.Backend),
		queue:   queueBackend,
		records: map[string]Record{},
		queues: map[string]struct{}{
			defaultLogicalQueue: {},
		},
		runningCancels: map[string]context.CancelFunc{},
		workers:        workerCount,
		jobTTL:         normalizeJobTTL(cfg.JobTTL),
		deadLetterTTL:  normalizeDeadLetterTTL(cfg.DeadLetterTTL),
		events:         events.NewBus(256),
	}

	if err := manager.loadPersistedRecords(); err != nil {
		manager.events.Close()
		_ = queueBackend.Close()
		cancel()
		return nil, err
	}

	for worker := 0; worker < workerCount; worker++ {
		manager.wg.Add(1)
		go manager.runWorker()
	}

	return manager, nil
}

func (m *Manager) Submit(ctx context.Context, spec contracts.RenderSpec) (contracts.RenderJob, error) {
	connection, queueName := queueSettings(spec)
	if !queueConnectionMatchesBackend(connection, m.backend) {
		return contracts.RenderJob{}, fmt.Errorf(
			"job queue connection %q is incompatible with runtime backend %q",
			connection,
			m.backend,
		)
	}

	record := Record{
		Job: contracts.RenderJob{
			ContractVersion: contracts.RenderJobContractVersion,
			ID:              generateJobID(spec.RequestID),
			RequestID:       spec.RequestID,
			Status:          "queued",
			Attempts:        0,
			MaxRetries:      resolveMaxRetries(spec),
			SubmittedAt:     time.Now().UTC().Format(time.RFC3339Nano),
		},
		Spec: spec,
	}

	if err := m.store.Save(record); err != nil {
		return contracts.RenderJob{}, err
	}

	m.mu.Lock()
	m.records[record.Job.ID] = record
	m.registerQueueLocked(queueName)
	m.mu.Unlock()

	select {
	case <-m.ctx.Done():
		m.mu.Lock()
		delete(m.records, record.Job.ID)
		m.mu.Unlock()
		_ = m.store.Delete(record.Job.ID)
		return contracts.RenderJob{}, context.Canceled
	default:
		if err := m.queue.Enqueue(ctx, queueName, record.Job.ID); err != nil {
			m.mu.Lock()
			delete(m.records, record.Job.ID)
			m.mu.Unlock()
			_ = m.store.Delete(record.Job.ID)
			return contracts.RenderJob{}, err
		}

		m.publishJobEvent(events.JobQueued, record, queueName, "", "")
		return cloneJob(record.Job), nil
	}
}

func (m *Manager) Get(jobID string) (contracts.RenderJob, error) {
	m.mu.RLock()
	record, ok := m.records[strings.TrimSpace(jobID)]
	m.mu.RUnlock()
	if !ok {
		return contracts.RenderJob{}, ErrJobNotFound
	}

	return cloneJob(record.Job), nil
}

func (m *Manager) List(limit int) contracts.RenderJobList {
	m.mu.RLock()
	items := make([]contracts.RenderJob, 0, len(m.records))
	for _, record := range m.records {
		items = append(items, cloneJob(record.Job))
	}
	m.mu.RUnlock()

	sort.Slice(items, func(left int, right int) bool {
		return compareTimestampDesc(items[left].SubmittedAt, items[right].SubmittedAt)
	})

	if limit > 0 && len(items) > limit {
		items = items[:limit]
	}

	return contracts.RenderJobList{
		ContractVersion: contracts.RenderJobListContractVersion,
		Count:           len(items),
		Items:           items,
	}
}

func (m *Manager) DeadLetters() (contracts.DeadLetterList, error) {
	items, err := m.store.ListDeadLetters()
	if err != nil {
		return contracts.DeadLetterList{}, err
	}

	return contracts.DeadLetterList{
		ContractVersion: contracts.DeadLetterListContractVersion,
		Count:           len(items),
		Items:           items,
	}, nil
}

func (m *Manager) RequeueDeadLetter(ctx context.Context, deadLetterID string) (contracts.RenderJob, error) {
	record, _, err := m.store.LoadDeadLetter(deadLetterID)
	if err != nil {
		if os.IsNotExist(err) {
			return contracts.RenderJob{}, ErrDeadLetterNotFound
		}

		return contracts.RenderJob{}, err
	}

	return m.Submit(ctx, record.Spec)
}

func (m *Manager) CleanupDeadLetters(olderThan time.Duration) (contracts.DeadLetterCleanup, error) {
	if olderThan <= 0 {
		olderThan = m.deadLetterTTL
	}

	removed, err := m.store.CleanupDeadLetters(olderThan)
	if err != nil {
		return contracts.DeadLetterCleanup{}, err
	}

	return contracts.DeadLetterCleanup{
		ContractVersion: contracts.DeadLetterCleanupContractVersion,
		Count:           len(removed),
		Removed:         removed,
	}, nil
}

func (m *Manager) CleanupJobs(olderThan time.Duration) ([]CleanupEntry, error) {
	if olderThan <= 0 {
		olderThan = m.jobTTL
	}

	removed, err := m.store.CleanupJobs(olderThan)
	if err != nil {
		return nil, err
	}

	if len(removed) == 0 {
		return removed, nil
	}

	m.mu.Lock()
	for _, item := range removed {
		delete(m.records, item.ID)
		delete(m.runningCancels, item.ID)
	}
	m.mu.Unlock()

	return removed, nil
}

func (m *Manager) Stats() Stats {
	m.mu.RLock()
	busy := m.busy
	workers := m.workers
	queueNames := m.queueNamesLocked()
	scheduledRetries := m.scheduledRetryCountLocked()
	m.mu.RUnlock()

	if m.backend == "redis" {
		queueNames = nil
	}

	return Stats{
		WorkerCount: workers,
		BusyWorkers: busy,
		QueueDepth:  m.queue.Depth(context.Background(), queueNames) + scheduledRetries,
		QueueLimit:  m.queue.Limit(),
	}
}

func (m *Manager) Close() {
	m.cancel()
	m.wg.Wait()
	if m.events != nil {
		m.events.Close()
	}
	_ = m.queue.Close()
}

func (m *Manager) loadPersistedRecords() error {
	records, err := m.store.LoadAll()
	if err != nil {
		return err
	}

	now := time.Now().UTC().Format(time.RFC3339Nano)
	loadedRecords := make(map[string]Record, len(records))
	loadedQueues := map[string]struct{}{
		defaultLogicalQueue: {},
	}
	saves := make([]contracts.RenderJob, 0)
	retries := make([]scheduledRetry, 0)

	for _, record := range records {
		_, queueName := queueSettings(record.Spec)
		job := cloneJob(record.Job)
		job.MaxRetries = resolveMaxRetries(record.Spec)

		switch {
		case job.Status == "queued" && strings.TrimSpace(job.NextRetryAt) != "":
			retries = append(retries, scheduledRetry{
				JobID:       job.ID,
				QueueName:   queueName,
				ScheduledAt: job.NextRetryAt,
			})
		case m.backend != "redis" && (job.Status == "queued" || job.Status == "running"):
			job.Status = "failed"
			job.Error = "job was interrupted before completion"
			job.CompletedAt = now
			job.Result = nil
			job.NextRetryAt = ""
			saves = append(saves, job)
			record.Job = job
			m.publishJobEvent(events.JobFailed, record, queueName, job.Error, "")
		}

		record.Job = job
		loadedRecords[job.ID] = record
		loadedQueues[normalizeLogicalQueueName(queueName)] = struct{}{}
	}

	for _, job := range saves {
		if err := m.store.SaveJob(job); err != nil {
			return err
		}
	}

	m.mu.Lock()
	m.records = loadedRecords
	m.queues = loadedQueues
	m.mu.Unlock()

	for _, retry := range retries {
		m.scheduleRetry(retry.JobID, retry.QueueName, retry.ScheduledAt)
	}

	return nil
}

func (m *Manager) runWorker() {
	defer m.wg.Done()

	for {
		queueNames := m.dequeueQueueNames()
		delivery, err := m.queue.Dequeue(m.ctx, queueNames)
		if err != nil {
			if errors.Is(err, context.Canceled) {
				return
			}

			time.Sleep(100 * time.Millisecond)
			continue
		}

		if delivery.JobID == "" {
			if m.ctx.Err() != nil {
				return
			}

			continue
		}

		select {
		case <-m.ctx.Done():
			return
		default:
			m.execute(delivery)
		}
	}
}

func (m *Manager) execute(delivery Delivery) {
	jobID := delivery.JobID

	m.mu.Lock()
	record, ok := m.records[jobID]
	if !ok {
		m.mu.Unlock()
		_ = m.queue.Ack(context.Background(), delivery)
		return
	}

	if record.Job.Status == "completed" || record.Job.Status == "failed" || record.Job.Status == "cancelled" {
		m.mu.Unlock()
		_ = m.queue.Ack(context.Background(), delivery)
		return
	}

	if shouldDelayRetry(record.Job) {
		_, queueName := queueSettings(record.Spec)
		scheduledAt := record.Job.NextRetryAt
		m.mu.Unlock()
		_ = m.queue.Ack(context.Background(), delivery)
		m.scheduleRetry(jobID, queueName, scheduledAt)
		return
	}

	record.Job.Status = "running"
	record.Job.StartedAt = time.Now().UTC().Format(time.RFC3339Nano)
	record.Job.Error = ""
	record.Job.Attempts++
	record.Job.Result = nil
	record.Job.NextRetryAt = ""
	m.records[jobID] = record
	m.busy++
	runCtx, runCancel := context.WithCancel(m.ctx)
	m.runningCancels[jobID] = runCancel
	m.mu.Unlock()

	_ = m.store.SaveJob(record.Job)
	m.publishJobEvent(events.JobRunning, record, delivery.QueueName, "", "")

	stopHeartbeat := m.startHeartbeat(delivery)
	result, err := m.runner(runCtx, record.Spec)
	stopHeartbeat()
	runCancel()

	recoverableInterrupt := m.backend == "redis" && err != nil && m.ctx.Err() != nil

	m.mu.Lock()
	record, ok = m.records[jobID]
	delete(m.runningCancels, jobID)
	if !ok {
		m.busy--
		m.mu.Unlock()
		if !recoverableInterrupt {
			_ = m.queue.Ack(context.Background(), delivery)
		}
		return
	}

	if record.Job.Status == "cancelled" {
		m.busy--
		m.mu.Unlock()
		if !recoverableInterrupt {
			_ = m.queue.Ack(context.Background(), delivery)
		}
		return
	}

	if recoverableInterrupt {
		record.Job.Status = "queued"
		record.Job.Error = ""
		record.Job.StartedAt = ""
		record.Job.CompletedAt = ""
		record.Job.Result = nil
		record.Job.NextRetryAt = ""
	} else {
		record.Job.CompletedAt = time.Now().UTC().Format(time.RFC3339Nano)
	}

	if !recoverableInterrupt && err != nil && shouldRetry(record.Job, record.Spec) {
		retryAt := time.Now().UTC().Add(resolveRetryDelay(record.Spec, record.Job.Attempts)).Format(time.RFC3339Nano)
		record.Job.Status = "queued"
		record.Job.Error = err.Error()
		record.Job.StartedAt = ""
		record.Job.CompletedAt = ""
		record.Job.Result = nil
		record.Job.NextRetryAt = retryAt
		record.Job.DeadLetter = nil
		m.records[jobID] = record
		m.busy--
		m.mu.Unlock()

		_ = m.store.SaveJob(record.Job)
		m.publishJobEvent(events.JobRetried, record, delivery.QueueName, err.Error(), retryAt)
		_ = m.queue.Ack(context.Background(), delivery)

		_, queueName := queueSettings(record.Spec)
		m.scheduleRetry(jobID, queueName, retryAt)
		return
	}

	if !recoverableInterrupt && err != nil {
		record.Job.Status = "failed"
		record.Job.Error = err.Error()
		record.Job.Result = nil
		record.Job.NextRetryAt = ""
	} else if !recoverableInterrupt {
		record.Job.Status = "completed"
		record.Job.Error = ""
		clonedResult := result
		record.Job.Result = &clonedResult
		record.Job.NextRetryAt = ""
		record.Job.DeadLetter = nil
	}

	m.records[jobID] = record
	m.busy--
	m.mu.Unlock()

	if record.Job.Status == "failed" {
		if deadLetter, deadLetterErr := m.store.SaveDeadLetter(record, record.Job.Error); deadLetterErr == nil && deadLetter != nil {
			record.Job.DeadLetter = deadLetter

			m.mu.Lock()
			current, ok := m.records[jobID]
			if ok {
				current.Job.DeadLetter = deadLetter
				record = current
				m.records[jobID] = current
			}
			m.mu.Unlock()
		}
	}

	_ = m.store.SaveJob(record.Job)
	switch record.Job.Status {
	case "failed":
		m.publishJobEvent(events.JobFailed, record, delivery.QueueName, record.Job.Error, "")
	case "completed":
		m.publishJobEvent(events.JobCompleted, record, delivery.QueueName, "", "")
	}
	if !recoverableInterrupt {
		_ = m.queue.Ack(context.Background(), delivery)
	}
}

func (m *Manager) dequeueQueueNames() []string {
	if m.backend == "redis" {
		return nil
	}

	m.mu.RLock()
	defer m.mu.RUnlock()

	return m.queueNamesLocked()
}

func (m *Manager) registerQueueLocked(queueName string) {
	m.queues[normalizeLogicalQueueName(queueName)] = struct{}{}
}

func (m *Manager) queueNamesLocked() []string {
	names := make([]string, 0, len(m.queues))
	for queueName := range m.queues {
		names = append(names, queueName)
	}

	sort.Strings(names)
	return collectQueueNames(names)
}

func (m *Manager) scheduledRetryCountLocked() int {
	count := 0
	for _, record := range m.records {
		if record.Job.Status == "queued" && strings.TrimSpace(record.Job.NextRetryAt) != "" {
			count++
		}
	}

	return count
}

func (m *Manager) startHeartbeat(delivery Delivery) func() {
	interval := m.queue.HeartbeatInterval()
	if interval <= 0 || delivery.messageID == "" {
		return func() {}
	}

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})

	go func() {
		ticker := time.NewTicker(interval)
		defer func() {
			ticker.Stop()
			close(done)
		}()

		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				heartbeatCtx, heartbeatCancel := context.WithTimeout(context.Background(), interval)
				_ = m.queue.Heartbeat(heartbeatCtx, delivery)
				heartbeatCancel()
			}
		}
	}()

	return func() {
		cancel()
		<-done
	}
}

func (m *Manager) scheduleRetry(jobID string, queueName string, scheduledAt string) {
	if strings.TrimSpace(scheduledAt) == "" {
		return
	}

	m.wg.Add(1)
	go func() {
		defer m.wg.Done()

		targetTime, ok := parseRetryTime(scheduledAt)
		if !ok {
			targetTime = time.Now().UTC()
		}

		for {
			waitFor := time.Until(targetTime)
			if waitFor < 0 {
				waitFor = 0
			}

			timer := time.NewTimer(waitFor)
			select {
			case <-m.ctx.Done():
				timer.Stop()
				return
			case <-timer.C:
			}

			record, ok := m.retryRecord(jobID, scheduledAt)
			if !ok {
				return
			}

			enqueueCtx, cancel := context.WithTimeout(context.Background(), time.Second)
			err := m.queue.Enqueue(enqueueCtx, queueName, jobID)
			cancel()
			if err == nil {
				record.Job.NextRetryAt = ""

				m.mu.Lock()
				current, ok := m.records[jobID]
				if ok && current.Job.Status == "queued" && current.Job.NextRetryAt == scheduledAt {
					current.Job.NextRetryAt = ""
					record = current
					m.records[jobID] = current
				} else {
					ok = false
				}
				m.mu.Unlock()

				if ok {
					_ = m.store.SaveJob(record.Job)
				}
				return
			}

			if m.ctx.Err() != nil || errors.Is(err, context.Canceled) {
				return
			}

			scheduledAt = time.Now().UTC().Add(time.Second).Format(time.RFC3339Nano)
			targetTime, _ = parseRetryTime(scheduledAt)

			m.mu.Lock()
			current, ok := m.records[jobID]
			if !ok || current.Job.Status != "queued" {
				m.mu.Unlock()
				return
			}

			current.Job.NextRetryAt = scheduledAt
			record = current
			m.records[jobID] = current
			m.mu.Unlock()

			_ = m.store.SaveJob(record.Job)
		}
	}()
}

func normalizeWorkerCount(count int) int {
	if count <= 0 {
		return 1
	}

	return count
}

func normalizeQueueDepth(depth int) int {
	if depth <= 0 {
		return 1
	}

	return depth
}

func normalizeJobTTL(ttl time.Duration) time.Duration {
	if ttl <= 0 {
		return 14 * 24 * time.Hour
	}

	return ttl
}

func normalizeDeadLetterTTL(ttl time.Duration) time.Duration {
	if ttl <= 0 {
		return 30 * 24 * time.Hour
	}

	return ttl
}

func generateJobID(requestID string) string {
	seed := strings.TrimSpace(requestID)
	if seed == "" {
		seed = time.Now().UTC().Format(time.RFC3339Nano)
	}

	sum := sha1.Sum([]byte(seed + ":" + time.Now().UTC().Format(time.RFC3339Nano)))
	return fmt.Sprintf("job-%d-%x", time.Now().UTC().Unix(), sum[:4])
}

func cloneJob(job contracts.RenderJob) contracts.RenderJob {
	cloned := job
	if job.DeadLetter != nil {
		deadLetter := *job.DeadLetter
		cloned.DeadLetter = &deadLetter
	}
	if job.Result != nil {
		result := *job.Result
		cloned.Result = &result
	}

	return cloned
}

func queueSettings(spec contracts.RenderSpec) (string, string) {
	connection, _ := spec.Queue["connection"].(string)
	queueName, _ := spec.Queue["queue"].(string)
	return strings.TrimSpace(connection), normalizeLogicalQueueName(queueName)
}

type scheduledRetry struct {
	JobID       string
	QueueName   string
	ScheduledAt string
}

func (m *Manager) retryRecord(jobID string, scheduledAt string) (Record, bool) {
	m.mu.RLock()
	defer m.mu.RUnlock()

	record, ok := m.records[jobID]
	if !ok {
		return Record{}, false
	}

	if record.Job.Status != "queued" || record.Job.NextRetryAt != scheduledAt {
		return Record{}, false
	}

	return record, true
}

func shouldRetry(job contracts.RenderJob, spec contracts.RenderSpec) bool {
	return job.Attempts <= resolveMaxRetries(spec)
}

func shouldDelayRetry(job contracts.RenderJob) bool {
	if job.Status != "queued" || strings.TrimSpace(job.NextRetryAt) == "" {
		return false
	}

	retryAt, ok := parseRetryTime(job.NextRetryAt)
	if !ok {
		return false
	}

	return retryAt.After(time.Now().UTC())
}

func parseRetryTime(value string) (time.Time, bool) {
	parsed, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(value))
	if err != nil {
		return time.Time{}, false
	}

	return parsed, true
}

func resolveMaxRetries(spec contracts.RenderSpec) int {
	value, ok := resolveExecutionInt(spec.Execution["retries"])
	if !ok || value <= 0 {
		return 0
	}

	return value
}

func resolveRetryDelay(spec contracts.RenderSpec, attempts int) time.Duration {
	baseSeconds := 1
	maxSeconds := 30

	if value, ok := resolveExecutionInt(spec.Execution["retryBackoff"]); ok && value > 0 {
		baseSeconds = value
	}

	if value, ok := resolveExecutionInt(spec.Execution["retryBackoffMax"]); ok && value > 0 {
		maxSeconds = value
	}

	delay := time.Duration(baseSeconds) * time.Second
	for retryIndex := 1; retryIndex < attempts; retryIndex++ {
		delay *= 2
		if delay >= time.Duration(maxSeconds)*time.Second {
			return time.Duration(maxSeconds) * time.Second
		}
	}

	maxDelay := time.Duration(maxSeconds) * time.Second
	if delay > maxDelay {
		return maxDelay
	}

	return delay
}

func resolveExecutionInt(value any) (int, bool) {
	switch typed := value.(type) {
	case int:
		return typed, true
	case int8:
		return int(typed), true
	case int16:
		return int(typed), true
	case int32:
		return int(typed), true
	case int64:
		return int(typed), true
	case float32:
		return int(typed), true
	case float64:
		return int(typed), true
	case string:
		if strings.TrimSpace(typed) == "" {
			return 0, false
		}

		var parsed int
		_, err := fmt.Sscanf(strings.TrimSpace(typed), "%d", &parsed)
		return parsed, err == nil
	default:
		return 0, false
	}
}

func compareTimestampDesc(left string, right string) bool {
	leftTime, leftOK := parseMaybeTimestamp(left)
	rightTime, rightOK := parseMaybeTimestamp(right)

	switch {
	case leftOK && rightOK:
		return leftTime.After(rightTime)
	case leftOK:
		return true
	case rightOK:
		return false
	default:
		return left > right
	}
}

func parseMaybeTimestamp(value string) (time.Time, bool) {
	parsed, err := time.Parse(time.RFC3339Nano, strings.TrimSpace(value))
	if err == nil {
		return parsed, true
	}

	parsed, err = time.Parse(time.RFC3339, strings.TrimSpace(value))
	return parsed, err == nil
}
