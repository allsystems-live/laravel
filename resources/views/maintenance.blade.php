<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} – back in a minute</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; text-align: center; }
        main { padding: 24px; }
        h1 { font-size: 1.75rem; margin: 0 0 12px; }
        p { margin: 0; color: #475569; }
    </style>
</head>
<body>
    <main>
        <h1>We're updating {{ config('app.name') }}</h1>
        <p>We'll be back in a minute. Please try again shortly.</p>
    </main>
</body>
</html>
