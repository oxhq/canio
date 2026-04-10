<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Oxhq\Canio\Facades\Canio;

Gate::define('viewCanioOps', static fn ($user = null): bool => true);

$exampleInvoice = static function (): array {
    return [
        'number' => 'INV-2026-0007',
        'customer' => 'Northwind Studio',
        'issued_at' => now()->toDateString(),
        'due_at' => now()->addDays(14)->toDateString(),
        'currency' => 'USD',
        'lines' => [
            ['description' => 'Design sprint retainer', 'quantity' => 1, 'amount' => 4200],
            ['description' => 'Production support', 'quantity' => 8, 'amount' => 120],
        ],
    ];
};

$javascriptProbe = static fn (string $probeUrl): array => [
    'title' => 'JavaScript Execution Probe',
    'probeUrl' => $probeUrl,
];

Route::get('/', function (): Response {
    return response(<<<'HTML'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Canio Example App</title>
    <style>
      body {
        margin: 0;
        font-family: "Instrument Sans", "Helvetica Neue", Arial, sans-serif;
        background:
          radial-gradient(circle at top left, rgba(199, 92, 55, 0.16), transparent 32%),
          linear-gradient(180deg, #f5efe6 0%, #ece1d0 100%);
        color: #1d140f;
      }
      main {
        max-width: 920px;
        margin: 72px auto;
        padding: 48px;
        border-radius: 28px;
        background: rgba(255, 251, 246, 0.9);
        box-shadow: 0 24px 60px rgba(61, 37, 22, 0.12);
        border: 1px solid rgba(61, 37, 22, 0.08);
      }
      h1 { margin: 0 0 12px; font-size: 48px; letter-spacing: -0.05em; }
      p { font-size: 18px; line-height: 1.6; }
      ul { padding-left: 20px; display: grid; gap: 10px; }
      a {
        color: #8a3c1f;
        font-weight: 600;
      }
      .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 16px;
        margin-top: 24px;
      }
      .card {
        border: 1px solid rgba(61, 37, 22, 0.08);
        border-radius: 20px;
        padding: 20px;
        background: rgba(255, 255, 255, 0.65);
      }
      .card h2 { margin: 0 0 10px; font-size: 20px; }
      .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; }
    </style>
  </head>
  <body>
    <main>
      <h1>Canio Example App</h1>
      <p>This sample app shows the package-first flow, plus explicit smoke routes for Canio Cloud in <span class="mono">sync</span> and <span class="mono">managed</span> modes.</p>
      <div class="grid">
        <div class="card">
          <h2>Local Package</h2>
          <ul>
            <li><a href="/invoices/preview">Preview a PDF invoice inline with zero manual runtime steps</a></li>
            <li><a href="/invoices/dispatch">Dispatch an async invoice job and jump into the optional ops panel</a></li>
            <li><a href="/probes/javascript">Open the raw JavaScript probe page in the browser</a></li>
            <li><a href="/probes/javascript/preview">Render the JavaScript probe through Canio PDF</a></li>
            <li><a href="/canio/ops">Open the opt-in Canio ops dashboard</a></li>
          </ul>
        </div>
        <div class="card">
          <h2>Canio Cloud Smoke</h2>
          <ul>
            <li><a href="/canio/cloud/sync/preview">Render via sync mode and publish snapshots/artifacts to Canio Cloud</a></li>
            <li><a href="/canio/cloud/managed/preview">Render via managed mode through Canio Cloud</a></li>
            <li><a href="/canio/cloud/sync/dispatch">Queue a sync job and persist the job record in Canio Cloud</a></li>
            <li><a href="/canio/cloud/managed/dispatch">Queue a managed job through Canio Cloud</a></li>
          </ul>
        </div>
      </div>
    </main>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});

Route::get('/invoices/preview', function () use ($exampleInvoice): Response {
    return Canio::view('pdf.invoice', ['invoice' => $exampleInvoice()])
        ->profile('invoice')
        ->title('Example Invoice Preview')
        ->debug()
        ->watch()
        ->stream('example-invoice.pdf');
});

Route::get('/invoices/dispatch', function () use ($exampleInvoice) {
    $job = Canio::view('pdf.invoice', ['invoice' => $exampleInvoice()])
        ->profile('invoice')
        ->title('Example Invoice Async Job')
        ->debug()
        ->watch()
        ->queue('redis', 'pdfs')
        ->dispatch();

    return redirect()->route('canio.ops.jobs.show', ['job' => $job->id()]);
});

Route::prefix('/canio/cloud')->group(function () use ($exampleInvoice): void {
    Route::get('{mode}/preview', function (string $mode) use ($exampleInvoice): Response {
        abort_unless(in_array($mode, ['sync', 'managed'], true), 404);

        config()->set('canio.cloud.mode', $mode);

        return Canio::view('pdf.invoice', ['invoice' => $exampleInvoice()])
            ->profile('invoice')
            ->title(sprintf('Canio Cloud %s Preview', ucfirst($mode)))
            ->debug()
            ->watch()
            ->stream(sprintf('example-invoice-%s.pdf', $mode));
    });

    Route::get('{mode}/dispatch', function (string $mode) use ($exampleInvoice): JsonResponse {
        abort_unless(in_array($mode, ['sync', 'managed'], true), 404);

        config()->set('canio.cloud.mode', $mode);

        $job = Canio::view('pdf.invoice', ['invoice' => $exampleInvoice()])
            ->profile('invoice')
            ->title(sprintf('Canio Cloud %s Async Job', ucfirst($mode)))
            ->debug()
            ->watch()
            ->dispatch();

        return response()->json([
            'mode' => $mode,
            'jobId' => $job->id(),
        ]);
    });
});

Route::get('/invoices/jobs/{job}', function (string $job) {
    return response()->json(Canio::job($job)->toArray());
})->name('invoices.job');

Route::get('/probes/javascript', function () use ($javascriptProbe): Response {
    return response()->view('pdf.javascript-probe', $javascriptProbe(request()->fullUrl()));
});

Route::get('/probes/javascript/preview', function () use ($javascriptProbe): Response {
    return Canio::view('pdf.javascript-probe', $javascriptProbe(url('/probes/javascript')))
        ->profile('report')
        ->title('JavaScript Execution Probe')
        ->debug()
        ->watch()
        ->stream('javascript-probe.pdf');
});
