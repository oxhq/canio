<?php

declare(strict_types=1);

namespace Oxhq\Canio\Http\Controllers;

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

        try {
            $status = $canio->runtimeStatus();
            $jobs = $canio->jobs(12);
            $artifacts = $canio->artifacts(12);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return view('canio::ops.dashboard', [
            'opsTitle' => (string) config('canio.ops.title', 'Canio Ops'),
            'errorMessage' => $error,
            'status' => $status,
            'jobs' => $jobs,
            'artifacts' => $artifacts,
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
}
