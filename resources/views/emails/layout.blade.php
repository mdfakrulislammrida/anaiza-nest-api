<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        p { margin: 0 0 16px; }
        a { color: #1B2A41; }
    </style>
</head>
<body style="margin:0;padding:0;background:#F6F1E7;color:#1B2A41;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F6F1E7;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">
                    <tr>
                        <td align="center" style="padding:0 0 24px;border-bottom:1px solid #AD8A50;">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $siteName }}" height="40" style="display:inline-block;height:40px;width:auto;border:0;">
                            @else
                                <span style="font-family:Georgia,'Times New Roman',serif;font-size:24px;line-height:32px;color:#1B2A41;">{{ $siteName }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 0 8px;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1B2A41;">
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:24px 0 0;border-top:1px solid #AD8A50;">
                            <p style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:24px;color:#1B2A41;">{{ $tagline }}</p>
                            <p style="margin:0;font-size:13px;line-height:20px;color:#6F675C;">
                                {{ $siteName }}
                                @if ($site->contact_phone) &middot; {{ $site->contact_phone }} @endif
                                @if ($site->contact_email) &middot; {{ $site->contact_email }} @endif
                            </p>
                            @if ($site->address)
                                <p style="margin:0;font-size:13px;line-height:20px;color:#6F675C;">{{ $site->address }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
