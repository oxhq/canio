<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice['number'] }}</title>
    <style>
      :root {
        color-scheme: light;
        --bg: #f7f1e8;
        --surface: rgba(255, 255, 255, 0.86);
        --ink: #201611;
        --muted: #6c5647;
        --accent: #a14a28;
      }

      * { box-sizing: border-box; }

      body {
        margin: 0;
        font-family: "Iowan Old Style", "Palatino Linotype", serif;
        background:
          radial-gradient(circle at top left, rgba(161, 74, 40, 0.12), transparent 32%),
          linear-gradient(180deg, #faf5ee 0%, var(--bg) 100%);
        color: var(--ink);
      }

      .page {
        width: 100%;
        min-height: 100vh;
        padding: 52px;
      }

      .card {
        background: var(--surface);
        border: 1px solid rgba(32, 22, 17, 0.08);
        border-radius: 28px;
        padding: 40px;
        box-shadow: 0 20px 60px rgba(32, 22, 17, 0.08);
      }

      .eyebrow {
        margin: 0 0 8px;
        font-size: 13px;
        letter-spacing: 0.2em;
        text-transform: uppercase;
        color: var(--muted);
      }

      h1 {
        margin: 0 0 16px;
        font-size: 44px;
        letter-spacing: -0.05em;
      }

      .meta,
      .totals {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 16px;
        margin-top: 24px;
      }

      .meta-block,
      .total-block {
        padding: 18px;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.72);
        border: 1px solid rgba(32, 22, 17, 0.06);
      }

      .label {
        display: block;
        margin-bottom: 8px;
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
        color: var(--muted);
      }

      table {
        width: 100%;
        margin-top: 28px;
        border-collapse: collapse;
      }

      th, td {
        padding: 14px 0;
        border-bottom: 1px solid rgba(32, 22, 17, 0.1);
        text-align: left;
      }

      th:last-child,
      td:last-child {
        text-align: right;
      }

      .grand-total {
        margin-top: 28px;
        padding-top: 20px;
        border-top: 2px solid rgba(161, 74, 40, 0.2);
        display: flex;
        justify-content: space-between;
        align-items: baseline;
        font-size: 28px;
      }

      .grand-total strong {
        color: var(--accent);
      }
    </style>
    <script>
      window.__CANIO_READY__ = false;
      window.addEventListener('load', () => {
        window.setTimeout(() => {
          window.__CANIO_READY__ = true;
        }, 250);
      });
    </script>
  </head>
  <body>
    @php
      $subtotal = collect($invoice['lines'])->sum(fn (array $line): float => $line['quantity'] * $line['amount']);
    @endphp
    <div class="page">
      <div class="card">
        <p class="eyebrow">Canio Example Invoice</p>
        <h1>{{ $invoice['number'] }}</h1>
        <p>Prepared for <strong>{{ $invoice['customer'] }}</strong> with a layout that is simple enough to read but rich enough to exercise Stagehand’s CDP render path.</p>

        <div class="meta">
          <div class="meta-block">
            <span class="label">Issued</span>
            <strong>{{ $invoice['issued_at'] }}</strong>
          </div>
          <div class="meta-block">
            <span class="label">Due</span>
            <strong>{{ $invoice['due_at'] }}</strong>
          </div>
        </div>

        <table>
          <thead>
            <tr>
              <th>Description</th>
              <th>Qty</th>
              <th>Amount</th>
            </tr>
          </thead>
          <tbody>
            @foreach ($invoice['lines'] as $line)
              <tr>
                <td>{{ $line['description'] }}</td>
                <td>{{ $line['quantity'] }}</td>
                <td>{{ $invoice['currency'] }} {{ number_format($line['quantity'] * $line['amount'], 2) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>

        <div class="grand-total">
          <span>Total</span>
          <strong>{{ $invoice['currency'] }} {{ number_format($subtotal, 2) }}</strong>
        </div>
      </div>
    </div>
  </body>
</html>
