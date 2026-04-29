<?php

declare(strict_types=1);

namespace Oxhq\Canio;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\File;
use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;
use Oxhq\Canio\Support\CanioCloudSyncFailureRecorder;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CanioManager
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        private readonly StagehandClient $stagehand,
        private readonly CanioCloudSyncer $cloudSyncer,
        private readonly CanioCloudSyncFailureRecorder $syncFailureRecorder,
        private readonly FilesystemManager $filesystems,
        private readonly ViewFactory $views,
        private readonly array $config,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function view(string $view, array $data = []): PendingRender
    {
        return PendingRender::forView($this, $view, $data, $this->defaults());
    }

    public function html(string $html): PendingRender
    {
        return PendingRender::forHtml($this, $html, $this->defaults());
    }

    public function url(string $url): PendingRender
    {
        return PendingRender::forUrl($this, $url, $this->defaults());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function template(string $slug, array $data = []): PendingRender
    {
        return PendingRender::forTemplate($this, $slug, $data, $this->defaults());
    }

    public function render(PendingRender $render): RenderResult
    {
        $spec = $this->prepareRenderSpec($render);
        $result = $this->stagehand->render($spec);

        try {
            $this->cloudSyncer->syncRender($spec, $result);
        } catch (\Throwable $exception) {
            report($exception);
            $this->syncFailureRecorder->record('render', [
                'requestId' => $result->requestId(),
                'jobId' => $result->jobId(),
                'status' => $result->status(),
                'artifactId' => $result->artifactId(),
                'sourceType' => data_get($spec->toArray(), 'source.type'),
                'outputMode' => data_get($spec->toArray(), 'output.mode'),
            ], $exception);
        }

        return $result;
    }

    public function dispatch(PendingRender $render): RenderJob
    {
        $spec = $this->prepareRenderSpec($render);
        $attributes = $spec->toArray();
        $attributes['queue'] = is_array($attributes['queue'] ?? null) ? $attributes['queue'] : [];
        $attributes['queue']['enabled'] = true;

        return $this->stagehand->dispatch(new RenderSpec($attributes));
    }

    public function job(string $jobId): RenderJob
    {
        return $this->stagehand->job($jobId);
    }

    /**
     * @return array<int, RenderJob>
     */
    public function jobs(int $limit = 20): array
    {
        $payload = $this->stagehand->jobs($limit);
        $items = data_get($payload, 'items', []);

        if (! is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $item): ?RenderJob => is_array($item) ? RenderJob::fromArray($item) : null,
            $items,
        )));
    }

    /**
     * @return iterable<array{id:string|null,event:string|null,data:array<string, mixed>}>
     */
    public function streamJobEvents(string $jobId, ?int $since = null): iterable
    {
        return $this->stagehand->streamJobEvents($jobId, $since);
    }

    public function cancelJob(string $jobId): RenderJob
    {
        return $this->stagehand->cancelJob($jobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifact(string $artifactId): array
    {
        return $this->stagehand->artifact($artifactId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function artifacts(int $limit = 20): array
    {
        $payload = $this->stagehand->artifacts($limit);
        $items = data_get($payload, 'items', []);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    public function retryJob(string $jobId): RenderJob
    {
        $job = $this->job($jobId);
        $deadLetterId = $job->deadLetterId();

        if ($deadLetterId === null) {
            throw new \RuntimeException(sprintf(
                'Job %s does not have a dead-letter snapshot available for retry.',
                $jobId,
            ));
        }

        return $this->requeueDeadLetter($deadLetterId);
    }

    /**
     * @return array<string, mixed>
     */
    public function deadLetters(): array
    {
        return $this->stagehand->deadLetters();
    }

    public function requeueDeadLetter(string $deadLetterId): RenderJob
    {
        return $this->stagehand->requeueDeadLetter($deadLetterId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cleanupDeadLetters(?int $olderThanDays = null): array
    {
        return $this->stagehand->cleanupDeadLetters($olderThanDays);
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeCleanup(?int $jobsOlderThanDays = null, ?int $artifactsOlderThanDays = null, ?int $deadLettersOlderThanDays = null): array
    {
        return $this->stagehand->runtimeCleanup($jobsOlderThanDays, $artifactsOlderThanDays, $deadLettersOlderThanDays);
    }

    public function replay(string $artifactId): RenderResult
    {
        return $this->stagehand->replay($artifactId);
    }

    public function save(PendingRender $render, string $path, ?string $disk = null): RenderResult
    {
        $result = $this->render($render->withOutputMode('inline', basename($path)));
        $bytes = $result->pdfBytes();

        if ($disk === null && $this->isAbsolutePath($path)) {
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $bytes);

            return $result->withStoredOutput($path);
        }

        $resolvedDisk = $disk ?: (string) ($this->defaults()['disk'] ?? $this->filesystems->getDefaultDriver());
        $this->filesystems->disk($resolvedDisk)->put($path, $bytes);

        return $result->withStoredOutput($path, $resolvedDisk);
    }

    public function download(PendingRender $render, string $fileName = 'document.pdf'): StreamedResponse
    {
        $result = $this->render($render->withOutputMode('inline', $fileName));
        $bytes = $result->pdfBytes();

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $fileName,
            ['Content-Type' => $result->contentType()],
        );
    }

    public function stream(PendingRender $render, string $fileName = 'document.pdf'): Response
    {
        $result = $this->render($render->withOutputMode('inline', $fileName));

        return response($result->pdfBytes(), 200, [
            'Content-Type' => $result->contentType(),
            'Content-Disposition' => sprintf('inline; filename="%s"', $fileName),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeStatus(): array
    {
        return $this->stagehand->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeRestart(): array
    {
        return $this->stagehand->restart();
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        $defaults = $this->config['defaults'] ?? [];

        return is_array($defaults) ? $defaults : [];
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $path) === 1;
    }

    private function prepareRenderSpec(PendingRender $render): RenderSpec
    {
        $attributes = $render->toRenderSpec()->toArray();
        $attributes = $this->applyProfileDefaults($attributes);
        $attributes = $this->normalizeSource($attributes);

        unset($attributes['contractVersion']);

        return new RenderSpec($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function applyProfileDefaults(array $attributes): array
    {
        $profile = trim((string) ($attributes['profile'] ?? ''));

        if ($profile === '') {
            return $attributes;
        }

        $profilePath = rtrim((string) ($this->config['profiles_path'] ?? ''), DIRECTORY_SEPARATOR);

        if ($profilePath === '') {
            return $attributes;
        }

        $file = $profilePath.DIRECTORY_SEPARATOR.$profile.'.php';

        if (! is_file($file)) {
            return $attributes;
        }

        $profileConfig = require $file;

        if (! is_array($profileConfig)) {
            return $attributes;
        }

        $presentation = is_array($attributes['presentation'] ?? null) ? $attributes['presentation'] : [];
        $document = is_array($attributes['document'] ?? null) ? $attributes['document'] : [];
        $execution = is_array($attributes['execution'] ?? null) ? $attributes['execution'] : [];
        $postprocess = is_array($attributes['postprocess'] ?? null) ? $attributes['postprocess'] : [];
        $debug = is_array($attributes['debug'] ?? null) ? $attributes['debug'] : [];

        if (array_key_exists('paper_size', $profileConfig) && ! array_key_exists('paperSize', $presentation)) {
            $presentation['paperSize'] = $profileConfig['paper_size'];
        }

        if (array_key_exists('margins', $profileConfig) && ! array_key_exists('margins', $presentation)) {
            $presentation['margins'] = $profileConfig['margins'];
        }

        if (array_key_exists('background', $profileConfig) && ! array_key_exists('background', $presentation)) {
            $presentation['background'] = (bool) $profileConfig['background'];
        }

        if (array_key_exists('format', $profileConfig) && ! array_key_exists('format', $presentation)) {
            $presentation['format'] = $profileConfig['format'];
        }

        if (array_key_exists('timeout', $profileConfig) && ! array_key_exists('timeout', $execution)) {
            $execution['timeout'] = (int) $profileConfig['timeout'];
        }

        if (array_key_exists('wait', $profileConfig) && ! array_key_exists('wait', $execution)) {
            $execution['wait'] = $profileConfig['wait'];
        }

        if (array_key_exists('tagged', $profileConfig) && ! array_key_exists('tagged', $document)) {
            $document['tagged'] = (bool) $profileConfig['tagged'];
        }

        if (array_key_exists('postprocess', $profileConfig) && is_array($profileConfig['postprocess'])) {
            $postprocess = [...$profileConfig['postprocess'], ...$postprocess];
        }

        if (array_key_exists('debug', $profileConfig) && is_array($profileConfig['debug'])) {
            $debug = [...$profileConfig['debug'], ...$debug];
        }

        $attributes['presentation'] = $presentation;
        $attributes['document'] = $document;
        $attributes['execution'] = $execution;
        $attributes['postprocess'] = $postprocess;
        $attributes['debug'] = $debug;

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function normalizeSource(array $attributes): array
    {
        $source = is_array($attributes['source'] ?? null) ? $attributes['source'] : [];
        $type = (string) ($source['type'] ?? '');
        $payload = is_array($source['payload'] ?? null) ? $source['payload'] : [];

        if ($type === 'cloud_template' && (string) ($this->config['cloud']['mode'] ?? 'off') !== 'managed') {
            throw new \RuntimeException('Canio cloud templates require cloud.mode=managed.');
        }

        if ($type === 'view') {
            $view = (string) ($payload['view'] ?? '');
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            $html = $this->views->make($view, $data)->render();

            $attributes['source'] = [
                'type' => 'html',
                'payload' => array_filter([
                    'html' => $html,
                    'baseUrl' => $this->baseUrl(),
                    'origin' => [
                        'type' => 'view',
                        'view' => $view,
                    ],
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ];

            return $this->normalizeHeaderAndFooterViews($attributes, $data);
        }

        if ($type === 'html' && ! array_key_exists('baseUrl', $payload)) {
            $baseUrl = $this->baseUrl();

            if ($baseUrl !== null) {
                $payload['baseUrl'] = $baseUrl;
                $attributes['source']['payload'] = $payload;
            }
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeHeaderAndFooterViews(array $attributes, array $data): array
    {
        $presentation = is_array($attributes['presentation'] ?? null) ? $attributes['presentation'] : [];

        if (isset($presentation['headerView']) && is_string($presentation['headerView']) && ! isset($presentation['headerHtml'])) {
            $presentation['headerHtml'] = $this->views->make($presentation['headerView'], $data)->render();
        }

        if (isset($presentation['footerView']) && is_string($presentation['footerView']) && ! isset($presentation['footerHtml'])) {
            $presentation['footerHtml'] = $this->views->make($presentation['footerView'], $data)->render();
        }

        $attributes['presentation'] = $presentation;

        return $attributes;
    }

    private function baseUrl(): ?string
    {
        $url = trim((string) config('app.url'));

        return $url === '' ? null : $url;
    }
}
