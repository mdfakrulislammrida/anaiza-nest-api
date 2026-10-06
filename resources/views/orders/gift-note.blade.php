<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Gift note</title>
    <style>
        @page { size: A6 portrait; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #f2f0ec; color: #1f2a44; }
        body { font-family: Georgia, "Noto Serif Bengali", "Nirmala UI", "Kalpurush", serif; }
        .card {
            width: 105mm; height: 148mm; margin: 0 auto; padding: 14mm 12mm;
            background: #fff; display: flex; flex-direction: column; justify-content: center;
            border: 0.3mm solid #d9d4c9;
        }
        .message { font-size: 13pt; line-height: 1.55; white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; }
        .from { margin: 9mm 0 0; font-size: 11pt; text-align: right; }
        .tools { text-align: center; padding: 12px; font: 14px/1.4 system-ui, sans-serif; }
        .tools button { font: inherit; padding: 8px 18px; border: 0; background: #1f2a44; color: #fff; border-radius: 2px; cursor: pointer; }
        @media print {
            html, body { background: #fff; }
            .tools { display: none; }
            .card { border: 0; margin: 0; }
        }
    </style>
</head>
<body>
    <div class="tools"><button type="button" onclick="window.print()">Print this card</button></div>
    <div class="card">
        @if ($message !== '')
            <p class="message">{{ $message }}</p>
        @endif
        @if (filled($sender))
            <p class="from">From {{ $sender }}</p>
        @endif
    </div>
</body>
</html>
