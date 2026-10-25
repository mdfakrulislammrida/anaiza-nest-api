<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0 8px;font-family:Arial,Helvetica,sans-serif;">
    <tbody>
        @foreach ($items as $item)
            <tr>
                <td width="76" valign="top" class="em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 12px 12px 0;width:76px;">
                    @include('emails.blocks.thumb', ['url' => $item['thumb'] ?? null, 'name' => $item['name'] ?? ''])
                </td>
                <td valign="top" class="em-text em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 8px 12px 0;font-size:15px;line-height:24px;color:#1B2A41;">
                    {{ $item['name'] ?? '' }}
                    @if (! empty($item['variant']))
                        <br><span class="em-muted" style="font-size:13px;color:#6F675C;">{{ $item['variant'] }}</span>
                    @endif
                </td>
                <td align="center" valign="top" class="em-text em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 8px;font-size:15px;line-height:24px;color:#1B2A41;">{{ (int) ($item['quantity'] ?? 1) }}</td>
                <td align="right" valign="top" class="em-text em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 0;font-size:15px;line-height:24px;color:#1B2A41;white-space:nowrap;">@if (($item['price'] ?? 0) > 0)৳{{ number_format(((int) $item['price']) * ((int) ($item['quantity'] ?? 1))) }}@endif</td>
            </tr>
        @endforeach
    </tbody>
</table>
