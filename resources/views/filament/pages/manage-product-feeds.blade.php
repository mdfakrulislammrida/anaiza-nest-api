<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">The three feeds</x-slot>
        <x-slot name="description">
            Always built from the live catalogue and kept for an hour. Each address carries a private token, so keep them to yourself. Product links point to
            <strong>{{ $storefront }}</strong>; change that in Site Settings under Product feeds when the shop moves to its real domain.
        </x-slot>

        <div class="space-y-6">
            @foreach ($feeds as $feed)
                <div x-data="{ copied: false }" class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="font-medium">{{ $feed['label'] }} <span class="text-sm text-gray-500">({{ $feed['note'] }})</span></p>
                        <p class="text-sm text-gray-500">{{ $feed['count'] }} {{ $feed['count'] === 1 ? 'item' : 'items' }}</p>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <input type="text" readonly value="{{ $feed['url'] }}" x-ref="url" x-on:focus="$el.select()"
                               class="min-w-0 flex-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-white/10 dark:bg-white/5">
                        <button type="button"
                                x-on:click="navigator.clipboard.writeText($refs.url.value).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                                class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium dark:border-white/10">
                            <span x-show="!copied">Copy</span><span x-show="copied" x-cloak>Copied</span>
                        </button>
                        <a href="{{ $feed['download'] }}" class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white">Download</a>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $this->form }}
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Health</x-slot>
        <x-slot name="description">
            Products that are left out of the feeds, or that are listed with something to improve. Nothing here stops a product being listed, apart from the ones marked Skipped.
        </x-slot>

        @if (count($health) === 0)
            <p class="text-sm text-gray-500">Nothing to fix. {{ $itemCount }} {{ $itemCount === 1 ? 'item is' : 'items are' }} ready to list.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            <th class="py-2 pr-4 font-medium">Product</th>
                            <th class="py-2 pr-4 font-medium">Status</th>
                            <th class="py-2 font-medium">Why</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($health as $row)
                            <tr class="border-b border-gray-100 align-top dark:border-white/5">
                                <td class="py-2 pr-4"><a href="{{ $row['edit'] }}" class="text-primary-600 underline">{{ $row['name'] }}</a></td>
                                <td class="py-2 pr-4">{{ $row['status'] }}</td>
                                <td class="py-2">
                                    <ul class="list-disc pl-4">
                                        @foreach ($row['reasons'] as $reason)
                                            <li>{{ $reason }}</li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
