A corporate enquiry has arrived.

Company:   {{ $enquiry->company }}
Name:      {{ $enquiry->name }}
Phone:     {{ $enquiry->phone }}
Email:     {{ $enquiry->email }}
Quantity:  {{ number_format($enquiry->quantity) }}
Needed by: {{ $enquiry->needed_by?->format('j F Y') ?? 'not given' }}

Products of interest:
{{ $enquiry->products_of_interest ?: 'not given' }}

Message:
{{ $enquiry->message ?: 'not given' }}

Open it in the admin under Corporate enquiries to set its status and add notes. Replying to this email writes to {{ $enquiry->email }}.
