<?php

declare(strict_types=1);

namespace Oxhq\Canio;

use Illuminate\Support\Str;
use Oxhq\Canio\Data\RenderJob;
use Oxhq\Canio\Data\RenderResult;
use Oxhq\Canio\Data\RenderSpec;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PendingRender
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $defaults
     * @param  array<string, mixed>  $presentation
     * @param  array<string, mixed>  $document
     * @param  array<string, mixed>  $execution
     * @param  array<string, mixed>  $postprocess
     * @param  array<string, mixed>  $debug
     * @param  array<string, mixed>  $queue
     * @param  array<string, mixed>  $output
     * @param  array<string, string>  $correlation
     */
    private function __construct(
        private readonly CanioManager $manager,
        private string $requestId,
        private array $source,
        private string $profile,
        private array $presentation,
        private array $document,
        private array $execution,
        private array $postprocess,
        private array $debug,
        private array $queue,
        private array $output,
        private array $correlation,
        private readonly array $defaults,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $defaults
     */
    public static function forView(CanioManager $manager, string $view, array $data, array $defaults): self
    {
        return self::make($manager, [
            'type' => 'view',
            'payload' => [
                'view' => $view,
                'data' => $data,
            ],
        ], $defaults);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    public static function forHtml(CanioManager $manager, string $html, array $defaults): self
    {
        return self::make($manager, [
            'type' => 'html',
            'payload' => ['html' => $html],
        ], $defaults);
    }

    /**
     * @param  array<string, mixed>  $defaults
     */
    public static function forUrl(CanioManager $manager, string $url, array $defaults): self
    {
        return self::make($manager, [
            'type' => 'url',
            'payload' => ['url' => $url],
        ], $defaults);
    }

    public function profile(string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    public function format(string $format): self
    {
        $this->presentation['format'] = $format;

        return $this;
    }

    public function paperSize(int|float $width, int|float $height, string $unit = 'mm'): self
    {
        $this->presentation['paperSize'] = [$width, $height, $unit];

        return $this;
    }

    public function landscape(bool $enabled = true): self
    {
        $this->presentation['landscape'] = $enabled;

        return $this;
    }

    public function margins(int|float $top, int|float $right, int|float $bottom, int|float $left): self
    {
        $this->presentation['margins'] = [$top, $right, $bottom, $left];

        return $this;
    }

    public function background(bool $enabled = true): self
    {
        $this->presentation['background'] = $enabled;

        return $this;
    }

    public function scale(float $scale): self
    {
        $this->presentation['scale'] = $scale;

        return $this;
    }

    public function pageRanges(string $ranges): self
    {
        $this->presentation['pageRanges'] = $ranges;

        return $this;
    }

    public function title(string $title): self
    {
        $this->document['title'] = $title;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function meta(array $meta): self
    {
        $this->document['meta'] = array_merge($this->document['meta'] ?? [], $meta);

        return $this;
    }

    public function tagged(bool $enabled = true): self
    {
        $this->document['tagged'] = $enabled;

        return $this;
    }

    public function locale(string $locale): self
    {
        $this->document['locale'] = $locale;

        return $this;
    }

    public function master(string $master): self
    {
        $this->presentation['master'] = $master;

        return $this;
    }

    public function headerView(string $view): self
    {
        $this->presentation['headerView'] = $view;

        return $this;
    }

    public function footerView(string $view): self
    {
        $this->presentation['footerView'] = $view;

        return $this;
    }

    public function pageNumbers(bool $enabled = true): self
    {
        $this->presentation['pageNumbers'] = $enabled;

        return $this;
    }

    public function watermark(string $label): self
    {
        $this->postprocess['watermark'] = $label;

        return $this;
    }

    public function encrypt(?string $owner = null, ?string $user = null): self
    {
        $this->postprocess['encrypt'] = array_filter([
            'owner' => $owner,
            'user' => $user,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return $this;
    }

    /**
     * @param  list<string>  $permissions
     */
    public function permissions(array $permissions): self
    {
        $this->postprocess['permissions'] = array_values($permissions);

        return $this;
    }

    /**
     * @param  list<string>  $documents
     */
    public function merge(array $documents): self
    {
        $this->postprocess['merge'] = array_values($documents);

        return $this;
    }

    public function split(string $ranges): self
    {
        $this->postprocess['split'] = $ranges;

        return $this;
    }

    public function optimize(bool $enabled = true): self
    {
        $this->postprocess['optimize'] = $enabled;

        return $this;
    }

    public function thumbnail(bool $enabled = true): self
    {
        $this->postprocess['thumbnail'] = $enabled;

        return $this;
    }

    public function timeout(int $seconds): self
    {
        $this->execution['timeout'] = $seconds;

        return $this;
    }

    public function retries(int $retries): self
    {
        $this->execution['retries'] = $retries;

        return $this;
    }

    public function retryBackoff(int $seconds, ?int $maxSeconds = null): self
    {
        $this->execution['retryBackoff'] = $seconds;

        if ($maxSeconds !== null) {
            $this->execution['retryBackoffMax'] = $maxSeconds;
        }

        return $this;
    }

    public function priority(string $priority): self
    {
        $this->execution['priority'] = $priority;

        return $this;
    }

    public function idempotencyKey(string $key): self
    {
        $this->execution['idempotencyKey'] = $key;

        return $this;
    }

    public function queue(?string $connection = null, ?string $queue = null): self
    {
        $this->queue['enabled'] = true;
        $this->queue['connection'] = $connection;
        $this->queue['queue'] = $queue;

        return $this;
    }

    public function watch(bool $enabled = true): self
    {
        $this->debug['watch'] = $enabled;

        return $this;
    }

    public function debug(bool $enabled = true): self
    {
        $this->debug['enabled'] = $enabled;

        return $this;
    }

    public function correlationId(string $scope, string $value): self
    {
        $this->correlation[$scope] = $value;

        return $this;
    }

    public function render(): RenderResult
    {
        return $this->manager->render($this);
    }

    public function dispatch(): RenderJob
    {
        $clone = clone $this;
        $clone->queue['enabled'] = true;

        return $this->manager->dispatch($clone);
    }

    public function save(string $path, ?string $disk = null): RenderResult
    {
        return $this->manager->save($this, $path, $disk);
    }

    public function download(string $fileName = 'document.pdf'): StreamedResponse
    {
        return $this->manager->download($this, $fileName);
    }

    public function stream(string $fileName = 'document.pdf'): Response
    {
        return $this->manager->stream($this, $fileName);
    }

    public function withOutputMode(string $mode, ?string $fileName = null): self
    {
        $clone = clone $this;
        $clone->output['mode'] = $mode;

        if ($fileName !== null && $fileName !== '') {
            $clone->output['fileName'] = $fileName;
        }

        return $clone;
    }

    public function toRenderSpec(): RenderSpec
    {
        return new RenderSpec([
            'requestId' => $this->requestId,
            'source' => $this->source,
            'profile' => $this->profile,
            'presentation' => $this->presentation,
            'document' => $this->document,
            'execution' => $this->execution,
            'postprocess' => $this->postprocess,
            'debug' => $this->debug,
            'queue' => $this->queue,
            'output' => $this->output,
            'correlation' => $this->correlation,
        ]);
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $defaults
     */
    private static function make(CanioManager $manager, array $source, array $defaults): self
    {
        return new self(
            manager: $manager,
            requestId: (string) Str::uuid(),
            source: $source,
            profile: (string) ($defaults['profile'] ?? 'invoice'),
            presentation: [
                'format' => (string) ($defaults['format'] ?? 'a4'),
            ],
            document: [],
            execution: [
                'timeout' => (int) ($defaults['timeout'] ?? 30),
                'retries' => (int) ($defaults['retries'] ?? 0),
            ],
            postprocess: [],
            debug: [
                'enabled' => (bool) ($defaults['debug'] ?? false),
                'watch' => (bool) ($defaults['watch'] ?? false),
            ],
            queue: ['enabled' => false],
            output: [
                'mode' => 'inline',
                'fileName' => 'document.pdf',
            ],
            correlation: [],
            defaults: $defaults,
        );
    }
}
