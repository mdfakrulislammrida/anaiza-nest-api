<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
                    <tr>
                        <td style="background:#18181b;padding:24px;text-align:center;">
                            @if ($siteSetting?->logo_url)
                                <img src="{{ $siteSetting->logo_url }}" alt="{{ $siteSetting->site_name }}" style="max-height:40px;">
                            @else
                                <span style="color:#ffffff;font-size:20px;font-weight:bold;">{{ $siteSetting?->site_name ?? config('app.name') }}</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 32px 8px;">
                            {!! $introHtml !!}
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <thead>
                                    <tr>
                                        <th align="left" style="border-bottom:1px solid #e4e4e7;padding:8px 0;font-size:12px;color:#71717a;text-transform:uppercase;">Item</th>
                                        <th align="center" style="border-bottom:1px solid #e4e4e7;padding:8px 0;font-size:12px;color:#71717a;text-transform:uppercase;">Qty</th>
                                        <th align="right" style="border-bottom:1px solid #e4e4e7;padding:8px 0;font-size:12px;color:#71717a;text-transform:uppercase;">Price</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($order->items as $item)
                                        <tr>
                                            <td style="border-bottom:1px solid #f4f4f5;padding:8px 0;font-size:14px;">
                                                {{ $item->product_name }}
                                                @if ($item->variant_name)
                                                    <br><span style="color:#71717a;font-size:12px;">{{ $item->variant_name }}: {{ $item->variant_value }}</span>
                                                @endif
                                            </td>
                                            <td align="center" style="border-bottom:1px solid #f4f4f5;padding:8px 0;font-size:14px;">{{ $item->quantity }}</td>
                                            <td align="right" style="border-bottom:1px solid #f4f4f5;padding:8px 0;font-size:14px;">৳{{ number_format($item->price * $item->quantity) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                                <tr>
                                    <td style="padding:4px 0;font-size:14px;color:#52525b;">Subtotal</td>
                                    <td align="right" style="padding:4px 0;font-size:14px;">৳{{ number_format($order->subtotal) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;font-size:14px;color:#52525b;">Delivery fee</td>
                                    <td align="right" style="padding:4px 0;font-size:14px;">৳{{ number_format($order->delivery_fee) }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:8px 0 0;font-size:16px;font-weight:bold;border-top:1px solid #e4e4e7;">Total</td>
                                    <td align="right" style="padding:8px 0 0;font-size:16px;font-weight:bold;border-top:1px solid #e4e4e7;">৳{{ number_format($order->total) }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 24px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td width="50%" style="vertical-align:top;padding-right:8px;">
                                        <p style="margin:0 0 4px;font-size:12px;color:#71717a;text-transform:uppercase;">Delivery address</p>
                                        <p style="margin:0;font-size:14px;">
                                            {{ $order->customer->name }}<br>
                                            {{ $order->customer->phone }}<br>
                                            {{ $order->customer->address }}<br>
                                            {{ $order->customer->thana }}, {{ $order->customer->district }}, {{ $order->customer->division }}
                                        </p>
                                    </td>
                                    <td width="50%" style="vertical-align:top;padding-left:8px;">
                                        <p style="margin:0 0 4px;font-size:12px;color:#71717a;text-transform:uppercase;">Payment method</p>
                                        <p style="margin:0;font-size:14px;text-transform:uppercase;">{{ $order->payment_method }}</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#fafafa;padding:20px 32px;text-align:center;border-top:1px solid #e4e4e7;">
                            <p style="margin:0;font-size:12px;color:#71717a;">
                                {{ $siteSetting?->site_name ?? config('app.name') }}
                                @if ($siteSetting?->contact_phone) &middot; {{ $siteSetting->contact_phone }} @endif
                                @if ($siteSetting?->contact_email) &middot; {{ $siteSetting->contact_email }} @endif
                            </p>
                            @if ($siteSetting?->address)
                                <p style="margin:4px 0 0;font-size:12px;color:#a1a1aa;">{{ $siteSetting->address }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
