<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            $deadLetters = $this->deadLetterItems($canio);
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

    public function cancelJob(string $jobId, CanioManager $canio, Request $request): RedirectResponse
    {
        $job = $canio->cancelJob($jobId);

        return $this->redirectBack($request, $job->id())
            ->with('canio_ops_notice', sprintf('Cancelled job %s.', $job->id()));
    }

    public function retryJob(string $jobId, CanioManager $canio, Request $request): RedirectResponse
    {
        $job = $canio->retryJob($jobId);

        return redirect()->route('canio.ops.jobs.show', ['job' => $job->id()])
            ->with('canio_ops_notice', sprintf('Retried job as %s.', $job->id()));
    }

    public function requeueDeadLetter(string $deadLetterId, CanioManager $canio): RedirectResponse
    {
        $job = $canio->requeueDeadLetter($deadLetterId);

        return redirect()->route('canio.ops.jobs.show', ['job' => $job->id()])
            ->with('canio_ops_notice', sprintf('Requeued %s as %s.', $deadLetterId, $job->id()));
    }

    public function restartRuntime(CanioManager $canio): RedirectResponse
    {
        $status = $canio->runtimeRestart();

        return redirect()->route('canio.ops.index')
            ->with('canio_ops_notice', sprintf(
                'Restarted Stagehand. Runtime state is now %s.',
                (string) data_get($status, 'runtime.state', 'unknown'),
            ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deadLetterItems(CanioManager $canio): array
    {
        $payload = $canio->deadLetters();
        $items = data_get($payload, 'items', []);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    private function redirectBack(Request $request, string $jobId): RedirectResponse
    {
        $redirectTo = (string) $request->input('redirect_to', '');

        if ($redirectTo === 'dashboard') {
            return redirect()->route('canio.ops.index');
        }

        return redirect()->route('canio.ops.jobs.show', ['job' => $jobId]);
    }
}
