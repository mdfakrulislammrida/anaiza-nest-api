@foreach ($lines as $line)
{{ $line }}
@endforeach
@if ($links !== [])

@foreach ($links as $label => $url)
{{ $label }}:
{{ $url }}

@endforeach
@endif
