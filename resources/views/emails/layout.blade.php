<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Tells mail apps this email has been made for a dark screen as well as a light one, so they do not invert it on their own. --}}
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subject }}</title>
    <style>
        :root { color-scheme: light dark; supported-color-schemes: light dark; }
        p { margin: 0 0 16px; }
        a { color: #1B2A41; }
        /* A dark screen: deep ink behind, a navy card, ivory text, champagne for the small words and the lines. The header band stays
           navy with its gold line, so the logo is always on a solid colour of its own. */
        @media (prefers-color-scheme: dark) {
            body, .em-outer { background-color: #141F30 !important; }
            .em-card { background-color: #1B2A41 !important; }
            .em-band { background-color: #1B2A41 !important; border-bottom-color: #AD8A50 !important; }
            .em-text, .em-text p, .em-text span { color: #F6F1E7 !important; }
            .em-muted, .em-text .em-muted, .em-text span.em-muted { color: #D4B786 !important; }
            .em-link, .em-text a, a.em-link { color: #D4B786 !important; }
            .em-hair { border-color: #6F675C !important; }
            .em-rule { border-color: #AD8A50 !important; }
            .em-panel { background-color: #141F30 !important; }
            .em-btn { background-color: #D4B786 !important; }
            a.em-btn-text { color: #1B2A41 !important; }
        }
        [data-ogsb] .em-outer { background-color: #141F30 !important; }
        [data-ogsb] .em-card { background-color: #1B2A41 !important; }
        [data-ogsc] .em-text, [data-ogsc] .em-text p, [data-ogsc] .em-text span { color: #F6F1E7 !important; }
        [data-ogsc] .em-muted, [data-ogsc] .em-text .em-muted { color: #D4B786 !important; }
    </style>
</head>
<body class="em-outer" bgcolor="#EDE4D3" style="margin:0;padding:0;background-color:#EDE4D3;color:#1B2A41;font-family:Arial,Helvetica,sans-serif;">
    <table role="presentation" class="em-outer" width="100%" cellpadding="0" cellspacing="0" bgcolor="#EDE4D3" style="background-color:#EDE4D3;">
        <tr>
            <td class="em-outer" align="center" bgcolor="#EDE4D3" style="padding:24px 12px;background-color:#EDE4D3;">
                <table role="presentation" class="em-card" width="600" cellpadding="0" cellspacing="0" bgcolor="#F6F1E7" style="width:100%;max-width:600px;background-color:#F6F1E7;">
                    <tr>
                        {{-- The header band: always navy, with the logo made for a dark background, or the name set as text in ivory. --}}
                        <td class="em-band" align="center" bgcolor="#1B2A41" style="padding:24px 16px;background-color:#1B2A41;border-bottom:2px solid #AD8A50;">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $siteName }}" height="40" style="display:inline-block;height:40px;width:auto;border:0;background-color:#1B2A41;">
                            @else
                                <span style="font-family:Fraunces,Georgia,'Times New Roman',serif;font-size:26px;line-height:32px;color:#F6F1E7;">{{ $siteName }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="em-card em-text" bgcolor="#F6F1E7" style="padding:32px 24px 8px;background-color:#F6F1E7;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:24px;color:#1B2A41;">
                            {!! $body !!}
                        </td>
                    </tr>
                    <tr>
                        <td class="em-card em-rule" align="center" bgcolor="#F6F1E7" style="padding:24px 24px 32px;background-color:#F6F1E7;border-top:1px solid #AD8A50;">
                            <p class="em-text" style="margin:0 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:16px;line-height:24px;color:#1B2A41;">{{ $tagline }}</p>
                            <p class="em-muted" style="margin:0;font-size:13px;line-height:20px;color:#6F675C;">
                                {{ $siteName }}
                                @if ($site->contact_phone) &middot; {{ $site->contact_phone }} @endif
                                @if ($site->contact_email) &middot; {{ $site->contact_email }} @endif
                            </p>
                            @if ($site->address)
                                <p class="em-muted" style="margin:0;font-size:13px;line-height:20px;color:#6F675C;">{{ $site->address }}</p>
                            @endif
                            @if (! empty($unsubscribeUrl))
                                <p class="em-muted" style="margin:16px 0 0;font-size:13px;line-height:20px;color:#6F675C;">Not for you? <a class="em-link" href="{{ $unsubscribeUrl }}" style="color:#6F675C;text-decoration:underline;">Unsubscribe</a> and we will not write about carts or reviews again.</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
