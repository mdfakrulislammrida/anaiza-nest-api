<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0 8px;font-family:Arial,Helvetica,sans-serif;">
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">
                    {{ $item['name'] ?? '' }}
                    @if (! empty($item['variant']))
                        <br><span style="font-size:13px;color:#6F675C;">{{ $item['variant'] }}</span>
                    @endif
                </td>
                <td align="center" style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ (int) ($item['quantity'] ?? 1) }}</td>
                <td align="right" style="border-bottom:1px solid #EDE4D3;padding:8px 0;font-size:15px;line-height:24px;color:#1B2A41;">৳{{ number_format(((int) ($item['price'] ?? 0)) * ((int) ($item['quantity'] ?? 1))) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
