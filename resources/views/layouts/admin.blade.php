<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Bus times admin</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 16px; background: #f4f4f5; color: #18181b; font-family: -apple-system, system-ui, sans-serif; line-height: 1.4; }
        main { max-width: 560px; margin: 0 auto; }
        h1 { font-size: 22px; margin: 0 0 16px; }
        h2 { font-size: 16px; margin: 0 0 8px; }
        h3 { font-size: 13px; margin: 0 0 4px; color: #71717a; text-transform: uppercase; letter-spacing: .04em; }
        .direction + .direction { margin-top: 16px; padding-top: 16px; border-top: 1px solid #e4e4e7; }
        .bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .card { background: #fff; border: 1px solid #e4e4e7; border-radius: 8px; padding: 16px; margin-bottom: 16px; }
        .current { margin: 0 0 12px; font-weight: 600; }
        .muted { color: #71717a; font-weight: 400; }
        .error { color: #b91c1c; margin: 8px 0 0; }
        .notice { color: #15803d; margin: 8px 0 0; }
        .hint { margin: 12px 0 0; font-weight: 600; }
        .loading { color: #71717a; margin: 8px 0 0; }
        .actions { display: flex; gap: 8px; margin: 12px 0 0; }
        .row { display: flex; gap: 8px; }
        input { flex: 1; min-width: 0; padding: 10px; border: 1px solid #a1a1aa; border-radius: 6px; font-size: 16px; }
        label { display: block; margin: 12px 0 4px; font-weight: 600; }
        button { padding: 10px 14px; border: 0; border-radius: 6px; background: #18181b; color: #fff; font-size: 15px; cursor: pointer; }
        button.plain { background: #e4e4e7; color: #18181b; }
        ul.choices { list-style: none; margin: 12px 0 0; padding: 0; }
        ul.choices li { margin-bottom: 6px; }
        ul.choices.busy { opacity: .5; pointer-events: none; }
        button:disabled { opacity: .6; cursor: progress; }
        ul.choices button { width: 100%; text-align: left; background: #f4f4f5; color: #18181b; border: 1px solid #d4d4d8; }
        code { display: block; padding: 8px; margin-bottom: 12px; background: #f4f4f5; border-radius: 6px; font-size: 12px; overflow-wrap: anywhere; }
    </style>
</head>
<body>
    <main>
        @yield('content')
    </main>
</body>
</html>
