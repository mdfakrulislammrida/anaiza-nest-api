@if (filled($order->courier_name) || filled($order->tracking_number))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;background:#EDE4D3;border-radius:2px;font-family:Arial,Helvetica,sans-serif;">
    <tr>
        <td style="padding:16px;font-size:15px;line-height:24px;color:#1B2A41;">
            @if (filled($order->courier_name))Courier: {{ $order->courier_name }}<br>@endif
            @if (filled($order->tracking_number))Tracking number: {{ $order->tracking_number }}@endif
        </td>
    </tr>
</table>
@endif
