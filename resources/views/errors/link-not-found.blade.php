<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Link unavailable — LinkForge</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: grid; place-items: center;
            font: 16px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f6f7f9; color: #16181d;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #101216; color: #e7e9ee; }
            .card { background: #181b21; border-color: #262a33; }
            code { background: #22262f; }
        }
        .card {
            max-width: 26rem; padding: 2.25rem 2rem; border-radius: 14px;
            background: #fff; border: 1px solid #e3e6eb; text-align: center;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.35rem; letter-spacing: -.01em; }
        p { margin: 0 0 .35rem; opacity: .75; font-size: .95rem; }
        code { padding: .12rem .4rem; border-radius: 5px; background: #eef0f4; font-size: .9em; }
        .mark { font-weight: 600; letter-spacing: .08em; text-transform: uppercase; font-size: .7rem; opacity: .5; }
    </style>
</head>
<body>
    <main class="card">
        <p class="mark">LinkForge</p>
        <h1>
            @switch($reason)
                @case('expired') This link has expired @break
                @case('disabled') This link has been disabled @break
                @case('cap_reached') This link has reached its click limit @break
                @default We couldn't find that link
            @endswitch
        </h1>
        <p>Nothing is served for <code>/{{ $slug }}</code>.</p>
        <p>Check the address, or ask whoever shared it for a fresh one.</p>
    </main>
</body>
</html>
