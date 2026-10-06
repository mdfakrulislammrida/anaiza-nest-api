A wallet order is waiting for its payment to be checked.

Order:        #{{ $order->id }}
Method:       {{ $label }}
Amount:       ৳{{ number_format($order->total) }}
Paid from:    {{ $order->payment_sender_number }}
Transaction:  {{ $order->payment_trx_id }}
Customer:     {{ $order->customer->name }}, {{ $order->customer->phone }}

Check the transaction ID and the amount in the {{ $label }} app, then open the order and choose Mark verified or Mark failed:
{{ $adminUrl }}
