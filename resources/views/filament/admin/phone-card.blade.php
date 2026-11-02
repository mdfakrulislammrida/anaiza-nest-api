{{--
    The phone card of a list row (App\Filament\Support\MobileCards). Shown only below 768 px; from there up the cell is hidden and the table's own columns show.
    Each row has a left side and a right side; a cell is text, a badge or a picture.
--}}
@php
    $rows = $rows instanceof \Closure ? $rows($getRecord()) : $rows;
@endphp
<div class="am-card">
    @foreach ($rows as $row)
        @php
            $filled = collect([...($row['left'] ?? []), ...($row['right'] ?? [])])->contains(fn ($cell) => array_key_exists('picture', $cell) || array_key_exists('badge', $cell) || filled($cell['text'] ?? null));
        @endphp
        @continue(! $filled)
        <div class="am-row">
            @foreach (['left', 'right'] as $side)
                @if (! empty($row[$side]))
                    <div class="am-side am-{{ $side }}">
                        @foreach ($row[$side] as $cell)
                            @if (array_key_exists('picture', $cell))
                                @if ($cell['picture'])
                                    <img class="am-picture" src="{{ $cell['picture'] }}" alt="" loading="lazy" width="48" height="48">
                                @else
                                    <span class="am-picture am-picture-empty" aria-hidden="true"></span>
                                @endif
                            @elseif (array_key_exists('badge', $cell))
                                <x-filament::badge :color="$cell['color'] ?? 'gray'" size="sm">{{ $cell['badge'] }}</x-filament::badge>
                            @else
                                <span @class(['am-text', 'am-strong' => ! empty($cell['strong']), 'am-muted' => ! empty($cell['muted']), 'am-wrap' => ! empty($cell['wrap'])])>{{ $cell['text'] }}</span>
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @endforeach
</div>
