<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Oxhq\Canio\Facades\Canio;

Gate::define('viewCanioOps', static fn ($user = null): bool => true);

function exampleInvoice(): array
{
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
}

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
        max-width: 840px;
        margin: 72px auto;
        padding: 48px;
        border-radius: 28px;
        background: rgba(255, 251, 246, 0.9);
        box-shadow: 0 24px 60px rgba(61, 37, 22, 0.12);
        border: 1px solid rgba(61, 37, 22, 0.08);
      }
      h1 { margin: 0 0 12px; font-size: 48px; letter-spacing: -0.05em; }
      p { font-size: 18px; line-height: 1.6; }
      ul { padding-left: 20px; }
      a {
        color: #8a3c1f;
        font-weight: 600;
      }
    </style>
  </head>
  <body>
    <main>
      <h1>Canio Example App</h1>
      <p>This sample app wires the local package, Stagehand runtime, Redis-backed async jobs, and the ops panel.</p>
      <ul>
        <li><a href="/invoices/preview">Preview a PDF invoice inline</a></li>
        <li><a href="/invoices/dispatch">Dispatch an async invoice job and jump to the ops panel</a></li>
        <li><a href="/canio/ops">Open the Canio ops dashboard</a></li>
      </ul>
    </main>
  </body>
</html>
HTML, 200, ['Content-Type' => 'text/html; charset=utf-8']);
});

Route::get('/invoices/preview', function () {
    return Canio::view('pdf.invoice', ['invoice' => exampleInvoice()])
        ->profile('invoice')
        ->title('Example Invoice Preview')
        ->debug()
        ->watch()
        ->stream('example-invoice.pdf');
});

Route::get('/invoices/dispatch', function () {
    $job = Canio::view('pdf.invoice', ['invoice' => exampleInvoice()])
        ->profile('invoice')
        ->title('Example Invoice Async Job')
        ->debug()
        ->watch()
        ->queue('redis', 'pdfs')
        ->dispatch();

    return redirect()->route('canio.ops.jobs.show', ['job' => $job->id()]);
});

Route::get('/invoices/jobs/{job}', function (string $job) {
    return response()->json(Canio::job($job)->toArray());
});
