<?php

declare(strict_types=1);

namespace Oxhq\Canio;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
use Oxhq\Canio\Bridge\CloudStagehandClient;
use Oxhq\Canio\Bridge\HttpStagehandClient;
use Oxhq\Canio\Console\CanioDoctorCommand;
use Oxhq\Canio\Console\CanioInstallCommand;
use Oxhq\Canio\Console\CanioRuntimeArtifactCommand;
use Oxhq\Canio\Console\CanioRuntimeCancelCommand;
use Oxhq\Canio\Console\CanioRuntimeCleanupCommand;
use Oxhq\Canio\Console\CanioRuntimeDeadLettersCleanupCommand;
use Oxhq\Canio\Console\CanioRuntimeDeadLettersCommand;
use Oxhq\Canio\Console\CanioRuntimeDeadLettersRequeueCommand;
use Oxhq\Canio\Console\CanioRuntimeInstallCommand;
use Oxhq\Canio\Console\CanioRuntimeJobCommand;
use Oxhq\Canio\Console\CanioRuntimeLogsCommand;
use Oxhq\Canio\Console\CanioRuntimeRestartCommand;
use Oxhq\Canio\Console\CanioRuntimeRetryCommand;
use Oxhq\Canio\Console\CanioRuntimeStatusCommand;
use Oxhq\Canio\Console\CanioServeCommand;
use Oxhq\Canio\Contracts\CanioCloudSyncer;
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Contracts\StagehandRuntimeBootstrapper;
use Oxhq\Canio\Support\CanioCloudRequestor;
use Oxhq\Canio\Support\CanioCloudSyncFailureRecorder;
use Oxhq\Canio\Support\EmbeddedStagehandRuntimeBootstrapper;
use Oxhq\Canio\Support\HttpCanioCloudSyncer;
use Oxhq\Canio\Support\NullCanioCloudSyncer;
use Oxhq\Canio\Support\NullStagehandRuntimeBootstrapper;
use Oxhq\Canio\Support\StagehandBinaryCompatibility;
use Oxhq\Canio\Support\StagehandBinaryResolver;
use Oxhq\Canio\Support\StagehandHealthProbe;
use Oxhq\Canio\Support\StagehandProcessLauncher;
use Oxhq\Canio\Support\StagehandReleaseInstaller;
use Oxhq\Canio\Support\StagehandServeCommandBuilder;

final class CanioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/canio.php', 'canio');

        $this->app->singleton(StagehandBinaryCompatibility::class);
        $this->app->singleton(StagehandBinaryResolver::class);
        $this->app->singleton(StagehandReleaseInstaller::class);
        $this->app->singleton(StagehandServeCommandBuilder::class);
        $this->app->singleton(StagehandProcessLauncher::class);
        $this->app->singleton(StagehandHealthProbe::class);
        $this->app->singleton(CanioCloudRequestor::class, function ($app): CanioCloudRequestor {
            return new CanioCloudRequestor((array) $app['config']->get('canio.cloud', []));
        });
        $this->app->singleton(CanioCloudSyncFailureRecorder::class);
        $this->app->singleton(StagehandRuntimeBootstrapper::class, function ($app): StagehandRuntimeBootstrapper {
            $cloud = (array) $app['config']->get('canio.cloud', []);
            $cloudMode = strtolower(trim((string) ($cloud['mode'] ?? 'off')));

            if ($cloudMode === 'managed') {
                return new NullStagehandRuntimeBootstrapper;
            }

            $runtime = (array) $app['config']->get('canio.runtime', []);
            $mode = strtolower(trim((string) ($runtime['mode'] ?? 'embedded')));

            if ($mode !== 'embedded') {
                return new NullStagehandRuntimeBootstrapper;
            }

            return new EmbeddedStagehandRuntimeBootstrapper(
                config: $runtime,
                resolver: $app->make(StagehandBinaryResolver::class),
                installer: $app->make(StagehandReleaseInstaller::class),
                commandBuilder: $app->make(StagehandServeCommandBuilder::class),
                launcher: $app->make(StagehandProcessLauncher::class),
                healthProbe: $app->make(StagehandHealthProbe::class),
            );
        });
        $this->app->singleton(CanioCloudSyncer::class, function ($app): CanioCloudSyncer {
            $cloud = (array) $app['config']->get('canio.cloud', []);
            $mode = strtolower(trim((string) ($cloud['mode'] ?? 'off')));

            if ($mode !== 'sync' || ! (bool) data_get($cloud, 'sync.enabled', true)) {
                return new NullCanioCloudSyncer;
            }

            return new HttpCanioCloudSyncer(
                requestor: $app->make(CanioCloudRequestor::class),
                config: $cloud,
            );
        });
        $this->app->singleton(StagehandClient::class, function ($app): StagehandClient {
            $cloud = (array) $app['config']->get('canio.cloud', []);
            $mode = strtolower(trim((string) ($cloud['mode'] ?? 'off')));

            if ($mode === 'managed') {
                return new CloudStagehandClient(
                    requestor: $app->make(CanioCloudRequestor::class),
                );
            }

            return new HttpStagehandClient(
                config: (array) $app['config']->get('canio.runtime', []),
                bootstrapper: $app->make(StagehandRuntimeBootstrapper::class),
            );
        });
        $this->app->singleton('canio', function ($app): CanioManager {
            return new CanioManager(
                stagehand: $app->make(StagehandClient::class),
                cloudSyncer: $app->make(CanioCloudSyncer::class),
                syncFailureRecorder: $app->make(CanioCloudSyncFailureRecorder::class),
                filesystems: $app['filesystem'],
                views: $app->make(ViewFactory::class),
                config: (array) $app['config']->get('canio', []),
            );
        });
        $this->app->singleton(CanioManager::class, fn ($app): CanioManager => $app->make('canio'));
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/canio.php' => config_path('canio.php'),
        ], 'canio-config');
        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/canio'),
        ], 'canio-views');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'canio');
        $this->loadRoutesFrom(__DIR__.'/../routes/canio.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CanioInstallCommand::class,
                CanioDoctorCommand::class,
                CanioServeCommand::class,
                CanioRuntimeInstallCommand::class,
                CanioRuntimeStatusCommand::class,
                CanioRuntimeRestartCommand::class,
                CanioRuntimeJobCommand::class,
                CanioRuntimeArtifactCommand::class,
                CanioRuntimeCancelCommand::class,
                CanioRuntimeRetryCommand::class,
                CanioRuntimeCleanupCommand::class,
                CanioRuntimeDeadLettersCommand::class,
                CanioRuntimeDeadLettersRequeueCommand::class,
                CanioRuntimeDeadLettersCleanupCommand::class,
                CanioRuntimeLogsCommand::class,
            ]);
        }
    }
}

if (! class_exists(\Garaekz\Canio\CanioServiceProvider::class, false)) {
    class_alias(CanioServiceProvider::class, \Garaekz\Canio\CanioServiceProvider::class);
}
