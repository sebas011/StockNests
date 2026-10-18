<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose a smaller report · StockNest</title>
    <style>body{font:1rem/1.6 system-ui,sans-serif;color:#172c2c;background:#f5f8f6;margin:0;padding:3rem 1.5rem}main{max-width:38rem;margin:auto;padding:2rem;background:#fff;border:1px solid #ccd6d6;border-radius:1rem}h1{font-size:1.6rem}a{color:#125c44}a:focus-visible{outline:3px solid #125c44;outline-offset:4px}nav{display:flex;gap:1.5rem;flex-wrap:wrap}</style>
</head>
<body>
    <main>
        <h1>Choose a smaller report</h1>
        <p>{{ $message }}</p>
        <p>Your records have not changed. CSV supports larger reports.</p>
        <nav aria-label="Report options">
            <a href="{{ $csvUrl }}">Download CSV with these filters</a>
            <a href="{{ url('/app/reports') }}">Return to reports</a>
        </nav>
    </main>
</body>
</html>
