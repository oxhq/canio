<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Oxhq\Canio\Http\Controllers\OpsDashboardController;
use Oxhq\Canio\Http\Controllers\StagehandWebhookController;
use Oxhq\Canio\Http\Middleware\AuthorizeOpsAccess;

Route::post(
    '/'.ltrim((string) config('canio.runtime.push.webhook.path', '/canio/webhooks/stagehand/jobs'), '/'),
    StagehandWebhookController::class,
)->name('canio.webhooks.stagehand.jobs');

if ((bool) config('canio.ops.enabled', false)) {
    Route::middleware([
        ...(array) config('canio.ops.middleware', ['web']),
        AuthorizeOpsAccess::class,
    ])
        ->prefix('/'.ltrim((string) config('canio.ops.path', '/canio/ops'), '/'))
        ->name('canio.ops.')
        ->group(function (): void {
            Route::get('/', [OpsDashboardController::class, 'index'])->name('index');
            Route::post('/runtime/restart', [OpsDashboardController::class, 'restartRuntime'])->name('runtime.restart');
            Route::post('/jobs/{job}/cancel', [OpsDashboardController::class, 'cancelJob'])->name('jobs.cancel');
            Route::post('/jobs/{job}/retry', [OpsDashboardController::class, 'retryJob'])->name('jobs.retry');
            Route::post('/dead-letters/{deadLetter}/requeue', [OpsDashboardController::class, 'requeueDeadLetter'])->name('dead-letters.requeue');
            Route::get('/jobs/{job}', [OpsDashboardController::class, 'showJob'])->name('jobs.show');
            Route::get('/artifacts/{artifact}', [OpsDashboardController::class, 'showArtifact'])->name('artifacts.show');
        });
}
