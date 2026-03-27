package jobs

import (
	"context"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/redis/go-redis/v9"
)

const redisConsumerGroup = "stagehand"

var enqueueScript = redis.NewScript(`
local limit = tonumber(ARGV[1])
if limit and limit > 0 then
  local depth = redis.call('XLEN', KEYS[1])
  if depth >= limit then
    return ''
  end
end
redis.call('SADD', KEYS[2], ARGV[2])
return redis.call('XADD', KEYS[1], '*', 'jobId', ARGV[3], 'queue', ARGV[2], 'queuedAt', ARGV[4])
`)

type redisBackend struct {
	client            *redis.Client
	queueKey          string
	queueSetKey       string
	groupName         string
	consumerName      string
	queueLimit        int
	blockTimeout      time.Duration
	leaseTimeout      time.Duration
	heartbeatInterval time.Duration
}

func newRedisBackend(cfg QueueConfig) (*redisBackend, error) {
	host := strings.TrimSpace(cfg.Redis.Host)
	if host == "" {
		host = "127.0.0.1"
	}

	port := cfg.Redis.Port
	if port <= 0 {
		port = 6379
	}

	queueKey := strings.TrimSpace(cfg.Redis.QueueKey)
	if queueKey == "" {
		queueKey = "canio:jobs:queue"
	}

	blockTimeout := cfg.Redis.BlockTimeout
	if blockTimeout <= 0 {
		blockTimeout = time.Second
	}

	leaseTimeout := normalizeLeaseTimeout(cfg.LeaseTimeout)
	heartbeatInterval := normalizeHeartbeatInterval(cfg.HeartbeatInterval, leaseTimeout)

	client := redis.NewClient(&redis.Options{
		Addr:     fmt.Sprintf("%s:%d", host, port),
		Password: cfg.Redis.Password,
		DB:       cfg.Redis.DB,
	})

	if err := client.Ping(context.Background()).Err(); err != nil {
		_ = client.Close()
		return nil, err
	}

	return &redisBackend{
		client:            client,
		queueKey:          queueKey,
		queueSetKey:       queueKey + ":queues",
		groupName:         redisConsumerGroup,
		consumerName:      newRedisConsumerName(),
		queueLimit:        normalizeQueueDepth(cfg.Depth),
		blockTimeout:      blockTimeout,
		leaseTimeout:      leaseTimeout,
		heartbeatInterval: heartbeatInterval,
	}, nil
}

func (b *redisBackend) Enqueue(ctx context.Context, queueName string, jobID string) error {
	queueName = normalizeLogicalQueueName(queueName)
	streamKey := b.queueKeyFor(queueName)

	if err := b.ensureStreamGroup(ctx, streamKey); err != nil {
		return err
	}

	result, err := enqueueScript.Run(
		ctx,
		b.client,
		[]string{streamKey, b.queueSetKey},
		b.queueLimit,
		queueName,
		jobID,
		time.Now().UTC().Format(time.RFC3339Nano),
	).Text()
	if err != nil {
		return err
	}

	if result == "" {
		return ErrQueueFull
	}

	return nil
}

func (b *redisBackend) Dequeue(ctx context.Context, queueNames []string) (Delivery, error) {
	queueNames = b.resolveQueueNames(ctx, queueNames)
	if err := b.ensureStreamGroups(ctx, queueNames); err != nil {
		return Delivery{}, err
	}

	if delivery, ok, err := b.claimStaleDelivery(ctx, queueNames); err != nil {
		return Delivery{}, err
	} else if ok {
		return delivery, nil
	}

	streamKeys := make([]string, 0, len(queueNames))
	streamIDs := make([]string, 0, len(queueNames))
	for _, queueName := range queueNames {
		streamKeys = append(streamKeys, b.queueKeyFor(queueName))
		streamIDs = append(streamIDs, ">")
	}

	streams, err := b.client.XReadGroup(ctx, &redis.XReadGroupArgs{
		Group:    b.groupName,
		Consumer: b.consumerName,
		Streams:  append(streamKeys, streamIDs...),
		Count:    1,
		Block:    b.blockTimeout,
	}).Result()
	if errors.Is(err, redis.Nil) {
		return Delivery{}, nil
	}
	if err != nil {
		return Delivery{}, err
	}

	for _, stream := range streams {
		if len(stream.Messages) == 0 {
			continue
		}

		return b.deliveryFromMessage(stream.Stream, stream.Messages[0]), nil
	}

	return Delivery{}, nil
}

func (b *redisBackend) Ack(ctx context.Context, delivery Delivery) error {
	if delivery.messageID == "" || delivery.streamKey == "" {
		return nil
	}

	if _, err := b.client.XAck(ctx, delivery.streamKey, b.groupName, delivery.messageID).Result(); err != nil {
		return err
	}

	_, err := b.client.XDel(ctx, delivery.streamKey, delivery.messageID).Result()
	return err
}

func (b *redisBackend) Heartbeat(ctx context.Context, delivery Delivery) error {
	if delivery.messageID == "" || delivery.streamKey == "" {
		return nil
	}

	_, err := b.client.XClaimJustID(ctx, &redis.XClaimArgs{
		Stream:   delivery.streamKey,
		Group:    b.groupName,
		Consumer: b.consumerName,
		MinIdle:  0,
		Messages: []string{delivery.messageID},
	}).Result()
	if errors.Is(err, redis.Nil) {
		return nil
	}

	return err
}

func (b *redisBackend) HeartbeatInterval() time.Duration {
	return b.heartbeatInterval
}

func (b *redisBackend) Depth(ctx context.Context, queueNames []string) int {
	queueNames = b.resolveQueueNames(ctx, queueNames)

	depth := 0
	for _, queueName := range queueNames {
		streamKey := b.queueKeyFor(queueName)
		streamDepth, err := b.client.XLen(ctx, streamKey).Result()
		if err != nil {
			if errors.Is(err, redis.Nil) {
				continue
			}

			return 0
		}

		pendingInfo, err := b.client.XPending(ctx, streamKey, b.groupName).Result()
		pendingCount := int64(0)
		if err == nil && pendingInfo != nil {
			pendingCount = pendingInfo.Count
		}

		waiting := streamDepth - pendingCount
		if waiting < 0 {
			waiting = 0
		}

		depth += int(waiting)
	}

	return depth
}

func (b *redisBackend) Limit() int {
	return b.queueLimit
}

func (b *redisBackend) Close() error {
	return b.client.Close()
}

func (b *redisBackend) claimStaleDelivery(ctx context.Context, queueNames []string) (Delivery, bool, error) {
	for _, queueName := range queueNames {
		streamKey := b.queueKeyFor(queueName)
		messages, _, err := b.client.XAutoClaim(ctx, &redis.XAutoClaimArgs{
			Stream:   streamKey,
			Group:    b.groupName,
			Consumer: b.consumerName,
			MinIdle:  b.leaseTimeout,
			Start:    "0-0",
			Count:    1,
		}).Result()
		if errors.Is(err, redis.Nil) {
			continue
		}
		if err != nil {
			return Delivery{}, false, err
		}
		if len(messages) == 0 {
			continue
		}

		return b.deliveryFromMessage(streamKey, messages[0]), true, nil
	}

	return Delivery{}, false, nil
}

func (b *redisBackend) deliveryFromMessage(streamKey string, message redis.XMessage) Delivery {
	return Delivery{
		JobID:     redisMessageString(message.Values["jobId"]),
		QueueName: b.logicalQueueName(streamKey),
		streamKey: streamKey,
		messageID: message.ID,
	}
}

func (b *redisBackend) resolveQueueNames(ctx context.Context, queueNames []string) []string {
	if len(queueNames) > 0 {
		return collectQueueNames(queueNames)
	}

	registered, err := b.client.SMembers(ctx, b.queueSetKey).Result()
	if err != nil {
		return []string{defaultLogicalQueue}
	}

	return collectQueueNames(registered)
}

func (b *redisBackend) ensureStreamGroups(ctx context.Context, queueNames []string) error {
	for _, queueName := range collectQueueNames(queueNames) {
		if err := b.ensureStreamGroup(ctx, b.queueKeyFor(queueName)); err != nil {
			return err
		}
	}

	return nil
}

func (b *redisBackend) ensureStreamGroup(ctx context.Context, streamKey string) error {
	err := b.client.XGroupCreateMkStream(ctx, streamKey, b.groupName, "0").Err()
	if err == nil || isRedisBusyGroup(err) {
		return nil
	}

	return err
}

func (b *redisBackend) queueKeyFor(queueName string) string {
	queueName = normalizeLogicalQueueName(queueName)
	if queueName == defaultLogicalQueue {
		return b.queueKey
	}

	return b.queueKey + ":" + queueName
}

func (b *redisBackend) logicalQueueName(streamKey string) string {
	if streamKey == b.queueKey {
		return defaultLogicalQueue
	}

	prefix := b.queueKey + ":"
	if strings.HasPrefix(streamKey, prefix) {
		return normalizeLogicalQueueName(strings.TrimPrefix(streamKey, prefix))
	}

	return defaultLogicalQueue
}

func normalizeLeaseTimeout(value time.Duration) time.Duration {
	if value <= 0 {
		return 45 * time.Second
	}

	return value
}

func normalizeHeartbeatInterval(value time.Duration, leaseTimeout time.Duration) time.Duration {
	if leaseTimeout <= 0 {
		leaseTimeout = 45 * time.Second
	}

	if value <= 0 {
		value = leaseTimeout / 3
	}

	if value <= 0 {
		value = time.Second
	}

	if value >= leaseTimeout {
		value = leaseTimeout / 2
	}

	if value <= 0 {
		value = time.Second
	}

	return value
}

func newRedisConsumerName() string {
	hostname, err := os.Hostname()
	if err != nil || strings.TrimSpace(hostname) == "" {
		hostname = "stagehand"
	}

	return fmt.Sprintf("%s-%d-%d", sanitizeConsumerSegment(hostname), os.Getpid(), time.Now().UTC().UnixNano())
}

func sanitizeConsumerSegment(value string) string {
	value = strings.TrimSpace(strings.ToLower(value))
	value = strings.ReplaceAll(value, " ", "-")
	value = strings.ReplaceAll(value, "_", "-")
	if value == "" {
		return "stagehand"
	}

	return value
}

func redisMessageString(value any) string {
	switch typed := value.(type) {
	case string:
		return typed
	case []byte:
		return string(typed)
	default:
		return fmt.Sprintf("%v", typed)
	}
}

func isRedisBusyGroup(err error) bool {
	return strings.Contains(strings.ToUpper(err.Error()), "BUSYGROUP")
}
