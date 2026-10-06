<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0 0;font-family:Arial,Helvetica,sans-serif;">
    <tr>
        <td style="padding:4px 0;font-size:15px;line-height:24px;color:#6F675C;">Subtotal</td>
        <td align="right" style="padding:4px 0;font-size:15px;line-height:24px;color:#1B2A41;">৳{{ number_format($order->subtotal) }}</td>
    </tr>
    <tr>
        <td style="padding:4px 0;font-size:15px;line-height:24px;color:#6F675C;">Delivery</td>
        <td align="right" style="padding:4px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ $order->delivery_fee > 0 ? '৳'.number_format($order->delivery_fee) : 'Free' }}</td>
    </tr>
    <tr>
        <td style="padding:8px 0 0;border-top:1px solid #AD8A50;font-size:18px;line-height:24px;font-weight:bold;color:#1B2A41;">Total</td>
        <td align="right" style="padding:8px 0 0;border-top:1px solid #AD8A50;font-size:18px;line-height:24px;font-weight:bold;color:#1B2A41;">৳{{ number_format($order->total) }}</td>
    </tr>
</table>
