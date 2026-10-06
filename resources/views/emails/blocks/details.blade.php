@php($paymentNote = \App\Support\WalletPayments::message($order->payment_status))
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:24px 0 0;font-family:Arial,Helvetica,sans-serif;">
    <tr>
        <td width="50%" valign="top" style="padding-right:16px;font-size:15px;line-height:24px;color:#1B2A41;">
            <span style="font-size:13px;color:#6F675C;">Delivery address</span><br>
            {{ $order->customer->name }}<br>
            {{ $order->customer->phone }}<br>
            {{ $order->customer->address }}<br>
            {{ implode(', ', array_filter([$order->customer->thana, $order->customer->district, $order->customer->division])) }}
        </td>
        <td width="50%" valign="top" style="font-size:15px;line-height:24px;color:#1B2A41;">
            <span style="font-size:13px;color:#6F675C;">Payment</span><br>
            {{ $order->payment_method === 'cod' ? 'Cash on delivery' : (\App\Support\WalletPayments::LABELS[$order->payment_method] ?? strtoupper($order->payment_method)) }}
            @if ($paymentNote)
                <br>{{ $paymentNote }}
                @if ($order->payment_status !== 'verified' && filled($phone))
                    <br><span style="font-size:13px;color:#6F675C;">Questions? Call {{ $phone }}.</span>
                @endif
            @endif
        </td>
    </tr>
</table>
