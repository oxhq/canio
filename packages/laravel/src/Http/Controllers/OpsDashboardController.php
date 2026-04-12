<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Oxhq\Canio\CanioManager;
use Throwable;

final class OpsDashboardController
{
    public function index(CanioManager $canio): View
    {
        $error = null;
        $status = null;
        $jobs = [];
        $artifacts = [];
        $deadLetters = [];

        try {
            $status = $canio->runtimeStatus();
            $jobs = $canio->jobs(12);
            $artifacts = $canio->artifacts(12);
            $deadLetterPayload = $canio->deadLetters();
            $deadLetters = array_slice(array_values(array_filter(
                is_array($deadLetterPayload['items'] ?? null) ? $deadLetterPayload['items'] : [],
                'is_array',
            )), 0, 8);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return view('canio::ops.dashboard', [
            'opsTitle' => (string) config('canio.ops.title', 'Canio Ops'),
            'errorMessage' => $error,
            'status' => $status,
            'jobs' => $jobs,
            'artifacts' => $artifacts,
            'deadLetters' => $deadLetters,
        ]);
    }

    public function showJob(string $jobId, CanioManager $canio): View
    {
        $job = $canio->job($jobId);
        $artifact = $job->artifactId() !== null ? $canio->artifact((string) $job->artifactId()) : null;

        return view('canio::ops.job', [
            'opsTitle' => (string) config('canio.ops.title', 'Canio Ops'),
            'job' => $job,
            'artifact' => $artifact,
            'refreshSeconds' => max(1, (int) config('canio.ops.refresh_seconds', 3)),
        ]);
    }

    public function showArtifact(string $artifactId, CanioManager $canio): View
    {
        return view('canio::ops.artifact', [
            'opsTitle' => (string) config('canio.ops.title', 'Canio Ops'),
            'artifact' => $canio->artifact($artifactId),
        ]);
    }

    public function restartRuntime(CanioManager $canio): RedirectResponse
    {
        try {
            $canio->runtimeRestart();

            return redirect()
                ->route('canio.ops.index')
                ->with('canio_ops_notice', 'Runtime restart requested.');
        } catch (Throwable $exception) {
            return redirect()
                ->route('canio.ops.index')
                ->with('canio_ops_notice', 'Runtime restart failed: '.$exception->getMessage());
        }
    }

    public function cancelJob(string $jobId, CanioManager $canio): RedirectResponse
    {
        try {
            $canio->cancelJob($jobId);

            return redirect()
                ->route('canio.ops.jobs.show', ['job' => $jobId])
                ->with('canio_ops_notice', sprintf('Cancellation requested for job %s.', $jobId));
        } catch (Throwable $exception) {
            return redirect()
                ->route('canio.ops.jobs.show', ['job' => $jobId])
                ->with('canio_ops_notice', 'Job cancellation failed: '.$exception->getMessage());
        }
    }

    public function retryJob(string $jobId, CanioManager $canio): RedirectResponse
    {
        try {
            $job = $canio->retryJob($jobId);

            return redirect()
                ->route('canio.ops.jobs.show', ['job' => $jobId])
                ->with('canio_ops_notice', sprintf('Retry queued as %s.', $job->id()));
        } catch (Throwable $exception) {
            return redirect()
                ->route('canio.ops.jobs.show', ['job' => $jobId])
                ->with('canio_ops_notice', 'Job retry failed: '.$exception->getMessage());
        }
    }

    public function requeueDeadLetter(string $deadLetterId, CanioManager $canio): RedirectResponse
    {
        try {
            $job = $canio->requeueDeadLetter($deadLetterId);

            return redirect()
                ->route('canio.ops.index')
                ->with('canio_ops_notice', sprintf('Dead-letter %s requeued as %s.', $deadLetterId, $job->id()));
        } catch (Throwable $exception) {
            return redirect()
                ->route('canio.ops.index')
                ->with('canio_ops_notice', 'Dead-letter requeue failed: '.$exception->getMessage());
        }
    }
}
