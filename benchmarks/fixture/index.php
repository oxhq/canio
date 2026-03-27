<?php

declare(strict_types=1);

$delayMs = max(0, (int) ($_GET['delay'] ?? 250));
$title = trim((string) ($_GET['title'] ?? 'Canio benchmark fixture'));
$heading = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$body = htmlspecialchars((string) ($_GET['body'] ?? 'This page keeps the browser busy long enough to exercise pool pressure.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $heading ?></title>
    <style>
      :root {
        color-scheme: light;
      }

      body {
        margin: 0;
        font-family: Inter, "Helvetica Neue", Arial, sans-serif;
        background:
          radial-gradient(circle at top left, rgba(0, 0, 0, 0.08), transparent 40%),
          linear-gradient(180deg, #f7f7f4 0%, #ece9df 100%);
        color: #1f1f1f;
      }

      main {
        max-width: 720px;
        margin: 64px auto;
        padding: 48px;
        background: rgba(255, 255, 255, 0.78);
        border: 1px solid rgba(0, 0, 0, 0.08);
        border-radius: 24px;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.08);
      }

      h1 {
        margin: 0 0 12px;
        font-size: 40px;
        letter-spacing: -0.04em;
      }

      p {
        line-height: 1.6;
        margin: 0 0 12px;
        font-size: 18px;
      }

      code {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        background: rgba(0, 0, 0, 0.06);
      }
    </style>
    <script>
      window.__CANIO_READY__ = false;
      window.addEventListener('load', () => {
        window.setTimeout(() => {
          window.__CANIO_READY__ = true;
        }, <?= $delayMs ?>);
      });
    </script>
  </head>
  <body>
    <main>
      <h1><?= $heading ?></h1>
      <p><?= $body ?></p>
      <p>Delay: <code><?= $delayMs ?>ms</code></p>
    </main>
  </body>
</html>
