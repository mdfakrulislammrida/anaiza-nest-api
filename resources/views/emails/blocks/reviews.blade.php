<p class="em-text" style="margin:24px 0 8px;font-family:Georgia,'Times New Roman',serif;font-size:18px;line-height:28px;color:#1B2A41;">How was it?</p>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;font-family:Arial,Helvetica,sans-serif;">
    @foreach ($order->items as $item)
        <tr>
            <td width="76" valign="middle" class="em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 12px 12px 0;width:76px;">
                @include('emails.blocks.thumb', ['url' => \App\Support\EmailThumbs::urlForItem($item), 'name' => $item->product_name])
            </td>
            <td valign="middle" class="em-text em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 8px 12px 0;font-size:15px;line-height:24px;color:#1B2A41;">{{ $item->product_name }}</td>
            <td align="right" valign="middle" class="em-hair" style="border-bottom:1px solid #EDE4D3;padding:12px 0;font-size:15px;line-height:24px;">
                @if ($item->product?->slug)
                    <a class="em-link" href="{{ \App\Support\Reviews\ReviewLinks::url($item) }}" style="color:#1B2A41;text-decoration:underline;">Review your purchase</a>
                @endif
            </td>
        </tr>
    @endforeach
</table>
