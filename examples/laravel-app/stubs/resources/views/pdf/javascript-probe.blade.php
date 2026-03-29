<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
      :root {
        color-scheme: light;
        --bg: #f3efe7;
        --ink: #1d140f;
        --muted: #665244;
        --accent: #0f0;
      }

      * { box-sizing: border-box; }

      body {
        margin: 0;
        min-height: 100vh;
        font-family: "Instrument Sans", "Helvetica Neue", Arial, sans-serif;
        background:
          radial-gradient(circle at top left, rgba(36, 163, 86, 0.12), transparent 28%),
          linear-gradient(180deg, #faf7f2 0%, var(--bg) 100%);
        color: var(--ink);
      }

      main {
        max-width: 920px;
        margin: 72px auto;
        padding: 56px;
        border-radius: 28px;
        background: rgba(255, 255, 255, 0.88);
        border: 1px solid rgba(29, 20, 15, 0.08);
        box-shadow: 0 24px 64px rgba(29, 20, 15, 0.08);
      }

      h1 {
        margin: 0 0 12px;
        font-size: 48px;
        letter-spacing: -0.05em;
      }

      p {
        margin: 0 0 18px;
        font-size: 18px;
        line-height: 1.65;
      }

      .panel {
        margin-top: 28px;
        padding: 22px;
        border-radius: 18px;
        background: #111;
        color: #d4ffd9;
        font: 15px/1.55 ui-monospace, SFMono-Regular, Menlo, monospace;
      }

      .label {
        display: block;
        margin-bottom: 10px;
        color: var(--muted);
        font-size: 12px;
        letter-spacing: 0.16em;
        text-transform: uppercase;
      }
    </style>
    <script>
      window.__CANIO_READY__ = false;

      window.addEventListener('load', () => {
        const probeUrl = @json($probeUrl);
        const targetText = 'codex ok ' + probeUrl;
        const node = document.getElementById('__codex_test__') || document.body.appendChild(Object.assign(document.createElement('div'), {
          id: '__codex_test__',
        }));

        node.style.cssText = 'position:fixed;z-index:2147483647;left:10px;top:10px;background:#000;color:#0f0;padding:10px;font:16px monospace';
        node.dataset.codexProbe = 'ok';
        node.textContent = targetText;

        document.body.dataset.codexProbe = 'ok';
        document.documentElement.dataset.codexProbe = 'ok';

        window.__CANIO_READY__ = true;
      });
    </script>
  </head>
  <body>
    <main>
      <span class="label">JavaScript Probe</span>
      <h1>{{ $title }}</h1>
      <p>This page exists to prove that browser-backed rendering executed JavaScript before the PDF snapshot was taken.</p>
      <p>The page starts without the probe node in the server HTML. On load, JavaScript injects a fixed black-and-green badge into the DOM and flips the explicit Canio readiness flag.</p>

      <div class="panel">
        <div>runtime badge expected in top-left corner</div>
        <div>probe url: {{ $probeUrl }}</div>
      </div>
    </main>
  </body>
</html>
