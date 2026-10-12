<x-filament-panels::page>
    @php
        $color = ['ok' => 'success', 'warn' => 'warning', 'fail' => 'danger'];
        $word = ['ok' => 'Fine', 'warn' => 'Look soon', 'fail' => 'Needs fixing'];
        $summary = [
            'ok' => 'Everything checked is working.',
            'warn' => 'Working, with something worth a look.',
            'fail' => 'Something needs fixing.',
        ];
    @endphp

    <x-filament::section>
        <x-slot name="heading">{{ $summary[$overall] }}</x-slot>

        <ul class="divide-y divide-gray-100 dark:divide-white/5">
            @foreach ($checks as $check)
                <li class="py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$color[$check['status']]">{{ $word[$check['status']] }}</x-filament::badge>
                        <span class="font-medium">{{ $check['label'] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $check['detail'] }}</p>
                    @if ($check['hint'] !== '')
                        <p class="mt-1 text-sm text-gray-500">{{ $check['hint'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Backups on this server</x-slot>
        <x-slot name="description">
            Each one holds the database and the uploaded pictures, not the .env file. Download one now and then and keep it somewhere that is not this server.
        </x-slot>

        @if ($backups === [])
            <p class="text-sm text-gray-500">No backup has been made yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="py-2 pr-4 font-medium">Made</th>
                        <th class="py-2 pr-4 font-medium">Kind</th>
                        <th class="py-2 pr-4 font-medium">Size</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($backups as $backup)
                        <tr class="border-b border-gray-100 dark:border-white/5" wire:key="backup-{{ $backup['name'] }}">
                            <td class="py-2 pr-4">{{ $backup['when'] }}</td>
                            <td class="py-2 pr-4">{{ ucfirst($backup['kind']) }}</td>
                            <td class="py-2 pr-4">{{ $backup['sizeLabel'] }}</td>
                            <td class="py-2 text-right">
                                <x-filament::button size="sm" color="gray" wire:click="download('{{ $backup['name'] }}')">Download</x-filament::button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
