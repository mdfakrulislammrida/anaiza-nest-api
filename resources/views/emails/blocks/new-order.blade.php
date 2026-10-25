@php($to = $order->recipient())
<p class="em-text" style="margin:0 0 16px;font-family:Georgia,'Times New Roman',serif;font-size:20px;line-height:28px;color:#1B2A41;">{{ $headline }}</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;">
    <tr>
        <td width="110" valign="top" class="em-muted" style="padding:4px 0;font-size:15px;line-height:24px;color:#6F675C;">Order</td>
        <td valign="top" class="em-text" style="padding:4px 0;font-size:15px;line-height:24px;color:#1B2A41;">#{{ $order->id }}</td>
    </tr>
    <tr>
        <td valign="top" class="em-muted" style="padding:4px 0;font-size:15px;line-height:24px;color:#6F675C;">Customer</td>
        <td valign="top" class="em-text" style="padding:4px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ trim(($to['name'] ?? '').', '.($to['phone'] ?? ''), ', ') }}</td>
    </tr>
    <tr>
        <td valign="top" class="em-muted" style="padding:4px 0;font-size:15px;line-height:24px;color:#6F675C;">Deliver to</td>
        <td valign="top" class="em-text" style="padding:4px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ $order->recipientAddressLine() ?: 'not given' }}</td>
    </tr>
</table>
@include('emails.blocks.items', ['order' => $order])
@include('emails.blocks.totals', ['order' => $order])
@if ($order->is_gift || filled($order->gift_note))
    <p class="em-text" style="margin:16px 0 0;font-size:15px;line-height:24px;color:#1B2A41;">
        @if ($order->is_gift)This is a gift{{ $order->gift_message ? ': "'.$order->gift_message.'"' : '.' }}<br>@endif
        @if (filled($order->gift_note))Note: {{ $order->gift_note }}@endif
    </p>
@endif
@foreach ($links as $label => $url)
    {!! \App\Support\Email\EmailBuilder::button($label, $url) !!}
@endforeach
