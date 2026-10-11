<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $ok ? 'You are unsubscribed' : 'This link has ended' }}</title>
</head>
<body style="margin:0;background:#F6F1E7;color:#1B2A41;font-family:Arial,Helvetica,sans-serif;">
    <main style="max-width:480px;margin:0 auto;padding:64px 24px;text-align:center;">
        @if ($ok)
            <h1 style="font-family:Georgia,'Times New Roman',serif;font-weight:normal;font-size:28px;line-height:36px;margin:0 0 16px;">You are unsubscribed.</h1>
            <p style="font-size:16px;line-height:24px;margin:0 0 24px;">We will not write to {{ $who }} about carts or reviews again. Your orders and their emails are not affected.</p>
        @else
            <h1 style="font-family:Georgia,'Times New Roman',serif;font-weight:normal;font-size:28px;line-height:36px;margin:0 0 16px;">This link has ended.</h1>
            <p style="font-size:16px;line-height:24px;margin:0 0 24px;">We could not read it. If you would like us to stop writing to you, reply to any of our emails and we will do it for you.</p>
        @endif
        <p style="font-family:Georgia,'Times New Roman',serif;font-size:16px;margin:0;">Gifted, beautifully.</p>
    </main>
</body>
</html>
