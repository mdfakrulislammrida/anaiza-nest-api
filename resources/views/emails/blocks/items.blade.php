<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:24px 0 0;font-family:Arial,Helvetica,sans-serif;">
    <thead>
        <tr>
            <th align="left" style="border-bottom:1px solid #AD8A50;padding:8px 0;font-size:13px;line-height:16px;font-weight:normal;color:#6F675C;">Item</th>
            <th align="center" style="border-bottom:1px solid #AD8A50;padding:8px 0;font-size:13px;line-height:16px;font-weight:normal;color:#6F675C;">Qty</th>
            <th align="right" style="border-bottom:1px solid #AD8A50;padding:8px 0;font-size:13px;line-height:16px;font-weight:normal;color:#6F675C;">Price</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">
                    {{ $item->product_name }}
                    @if ($item->variant_name)
                        <br><span style="font-size:13px;color:#6F675C;">{{ $item->variant_name }}: {{ $item->variant_value }}</span>
                    @endif
                </td>
                <td align="center" style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ $item->quantity }}</td>
                <td align="right" style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">৳{{ number_format($item->price * $item->quantity) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
