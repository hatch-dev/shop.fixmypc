<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $ok ? 'Unsubscribed' : 'Link not valid' }} · {{ $brand->store_short }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
               background: {{ $brand->canvas }}; padding: 24px;
               font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
               color: {{ $brand->ink_soft }}; }
        .card { background: #fff; border: 1px solid {{ $brand->line }}; border-radius: 16px;
                padding: 40px 32px; max-width: 460px; width: 100%; text-align: center;
                box-shadow: 0 8px 30px -12px rgba(15, 23, 42, .18); }
        .logo { max-width: 150px; height: auto; margin-bottom: 24px; }
        h1 { margin: 0 0 12px; font-size: 22px; line-height: 30px; color: {{ $brand->ink }}; }
        p { margin: 0 0 8px; font-size: 15px; line-height: 24px; color: {{ $brand->muted }}; }
        .email { font-weight: 600; color: {{ $brand->ink }}; word-break: break-all; }
        .btn { display: inline-block; margin-top: 24px; padding: 12px 26px; border-radius: 999px;
               background: {{ $brand->primary }}; color: {{ $brand->on_primary }};
               text-decoration: none; font-weight: 600; font-size: 15px; }
        @media (prefers-color-scheme: dark) {
            body { background: #0b1220; color: #e2e8f0; }
            .card { background: #111a2b; border-color: #24314c; }
            h1, .email { color: #f1f5f9; }
            p { color: #94a3b8; }
        }
    </style>
</head>
<body>
<div class="card">
    @if ($brand->logo)
        <img class="logo" src="{{ $brand->logo }}" alt="{{ $brand->store_short }}">
    @endif

    @if ($ok)
        <h1>You're unsubscribed</h1>
        @if ($email)
            <p><span class="email">{{ $email }}</span> has been removed from our mailing list.</p>
        @endif
        <p>You'll still receive order confirmations and other service messages about your account.</p>
    @else
        <h1>This link isn't valid</h1>
        <p>The unsubscribe link has expired or was altered. Email
            <a href="mailto:{{ $brand->support_email }}">{{ $brand->support_email }}</a>
            and we'll take you off the list.</p>
    @endif

    <a class="btn" href="{{ $brand->site_url }}">Back to {{ $brand->store_short }}</a>
</div>
</body>
</html>
