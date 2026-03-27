<?php

declare(strict_types=1);

namespace Oxhq\Canio\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Oxhq\Canio\CanioServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            CanioServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.env', 'testing');
        $app['config']->set('app.url', 'https://canio.test');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('canio.ops.enabled', true);
        $app['config']->set('canio.ops.access.preset', 'local-open');
        $app['config']->set('filesystems.default', 'local');
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => storage_path('app'),
        ]);
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);
    }
}
