<?php

declare(strict_types=1);

namespace Oxhq\Canio;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\ServiceProvider;
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
use Oxhq\Canio\Contracts\StagehandClient;
use Oxhq\Canio\Support\StagehandBinaryResolver;

final class CanioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/canio.php', 'canio');

        $this->app->singleton(StagehandBinaryResolver::class);
        $this->app->singleton(StagehandClient::class, function ($app): StagehandClient {
            return new HttpStagehandClient((array) $app['config']->get('canio.runtime', []));
        });
        $this->app->singleton('canio', function ($app): CanioManager {
            return new CanioManager(
                stagehand: $app->make(StagehandClient::class),
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
