<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Canio Ops' }}</title>
    @hasSection('meta')
        @yield('meta')
    @endif
    <style>
        :root {
            --bg: #f4f1ea;
            --panel: rgba(255, 255, 255, 0.88);
            --panel-strong: #fffdf8;
            --ink: #1c1a18;
            --muted: #6d655f;
            --border: rgba(28, 26, 24, 0.12);
            --accent: #0c7a6b;
            --accent-soft: rgba(12, 122, 107, 0.12);
            --warning: #bc6c25;
            --warning-soft: rgba(188, 108, 37, 0.12);
            --danger: #b42318;
            --danger-soft: rgba(180, 35, 24, 0.12);
            --shadow: 0 22px 44px rgba(60, 43, 19, 0.12);
            --radius: 24px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: "Iowan Old Style", "Palatino Linotype", "Book Antiqua", Georgia, serif;
            color: var(--ink);
            background:
                radial-gradient(circle at top left, rgba(12, 122, 107, 0.14), transparent 28rem),
                radial-gradient(circle at top right, rgba(188, 108, 37, 0.12), transparent 24rem),
                linear-gradient(180deg, #fbf8f2 0%, var(--bg) 100%);
        }

        a {
            color: inherit;
        }

        .shell {
            width: min(1200px, calc(100vw - 2rem));
            margin: 0 auto;
            padding: 2rem 0 4rem;
        }

        .hero {
            display: flex;
            justify-content: space-between;
            gap: 1.5rem;
            align-items: flex-start;
            padding: 1.6rem 1.8rem;
            border: 1px solid var(--border);
            border-radius: calc(var(--radius) + 4px);
            background: linear-gradient(135deg, rgba(255, 253, 248, 0.96), rgba(243, 248, 246, 0.9));
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }

        .eyebrow {
            margin: 0 0 0.55rem;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            font-size: 0.75rem;
            color: var(--muted);
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 4vw, 3.3rem);
            line-height: 0.95;
        }

        .hero p {
            margin: 0.75rem 0 0;
            color: var(--muted);
            max-width: 42rem;
            line-height: 1.55;
        }

        .hero-nav {
            display: flex;
            gap: 0.7rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .button,
        button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            padding: 0.7rem 1rem;
            font: inherit;
            font-size: 0.95rem;
            cursor: pointer;
            background: var(--ink);
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
        }

        .button.secondary,
        button.secondary {
            background: white;
            color: var(--ink);
            border: 1px solid var(--border);
        }

        .button.ghost,
        button.ghost {
            background: transparent;
            color: var(--ink);
            border: 1px solid var(--border);
        }

        .button.warning,
        button.warning {
            background: var(--warning);
        }

        .button.danger,
        button.danger {
            background: var(--danger);
        }

        .notice,
        .error {
            border-radius: 18px;
            padding: 0.95rem 1rem;
            margin-bottom: 1rem;
            border: 1px solid var(--border);
        }

        .notice {
            background: var(--accent-soft);
            color: #0b4e44;
        }

        .error {
            background: var(--danger-soft);
            color: #6f1610;
        }

        .grid {
            display: grid;
            gap: 1rem;
        }

        .grid.metrics {
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            margin-bottom: 1rem;
        }

        .panel {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
        }

        .metric {
            padding: 1rem 1.1rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.95), rgba(255, 252, 246, 0.92));
        }

        .metric strong {
            display: block;
            font-size: 1.8rem;
            margin-top: 0.25rem;
        }

        .metric span {
            color: var(--muted);
            font-size: 0.82rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .section-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 1.15rem 1.25rem 0;
        }

        .section-head h2 {
            margin: 0;
            font-size: 1.2rem;
        }

        .section-head p {
            margin: 0.2rem 0 0;
            color: var(--muted);
            font-size: 0.94rem;
        }

        .section-body {
            padding: 1rem 1.25rem 1.25rem;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.85rem 0.7rem;
            border-bottom: 1px solid rgba(28, 26, 24, 0.08);
            text-align: left;
            vertical-align: top;
            font-size: 0.94rem;
        }

        th {
            color: var(--muted);
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .mono {
            font-family: "SFMono-Regular", "Menlo", "Monaco", monospace;
            font-size: 0.85rem;
            word-break: break-word;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 0.32rem 0.65rem;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            background: rgba(28, 26, 24, 0.08);
        }

        .badge.completed {
            color: #0b4e44;
            background: var(--accent-soft);
        }

        .badge.running,
        .badge.queued {
            color: #5c3d18;
            background: var(--warning-soft);
        }

        .badge.failed,
        .badge.cancelled {
            color: #6f1610;
            background: var(--danger-soft);
        }

        .stack {
            display: grid;
            gap: 1rem;
        }

        .detail-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: 1.3fr 0.9fr;
        }

        .split-panels {
            grid-template-columns: 1.1fr 0.9fr;
            margin-top: 1rem;
        }

        .kv {
            display: grid;
            grid-template-columns: minmax(120px, 180px) 1fr;
            gap: 0.8rem 1rem;
        }

        .kv dt {
            color: var(--muted);
            font-size: 0.83rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .kv dd {
            margin: 0;
        }

        .actions {
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }

        .empty {
            padding: 1.1rem;
            border-radius: 18px;
            border: 1px dashed var(--border);
            color: var(--muted);
            background: rgba(255, 255, 255, 0.55);
        }

        pre {
            margin: 0;
            white-space: pre-wrap;
            word-break: break-word;
            font-size: 0.83rem;
            line-height: 1.55;
        }

        @media (max-width: 840px) {
            .hero {
                flex-direction: column;
            }

            .hero-nav {
                justify-content: flex-start;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .split-panels {
                grid-template-columns: 1fr;
            }

            .kv {
                grid-template-columns: 1fr;
                gap: 0.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="shell">
        <header class="hero">
            <div>
                <p class="eyebrow">Operations Surface</p>
                <h1>{{ $title ?? 'Canio Ops' }}</h1>
                <p>{{ $subtitle ?? 'Inspect jobs, artifacts, dead-letters, and runtime health without leaving the app.' }}</p>
            </div>
            <nav class="hero-nav">
                <a class="button secondary" href="{{ route('canio.ops.index') }}">Dashboard</a>
                @yield('hero_actions')
            </nav>
        </header>

        @if (session('canio_ops_notice'))
            <div class="notice">{{ session('canio_ops_notice') }}</div>
        @endif

        @if (!empty($errorMessage ?? null))
            <div class="error">{{ $errorMessage }}</div>
        @endif

        @yield('content')
    </div>
</body>
</html>
