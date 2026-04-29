<?php

declare(strict_types=1);

namespace Oxhq\Canio\Support;

final class BrowserBundleInstallResult
{
    public function __construct(
        public readonly string $product,
        public readonly string $version,
        public readonly string $channel,
        public readonly string $platform,
        public readonly string $installPath,
        public readonly string $executablePath,
        public readonly string $downloadUrl,
    ) {}
}
