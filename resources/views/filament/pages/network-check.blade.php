<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">What the server received for this page</x-slot>
        <x-slot name="description">
            Every limit in the shop (orders, tracking, forms, login) is counted per visitor address. This shows whether that address is the visitor's own or the CDN's.
        </x-slot>

        <dl class="grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-gray-200 p-4 dark:border-white/10">
                <dt class="text-sm text-gray-500">Connection address (REMOTE_ADDR)</dt>
                <dd class="mt-1 font-mono text-base">{{ $remote ?: '(none)' }}</dd>
            </div>
            <div class="rounded-lg border p-4 {{ $verdict === 'ok' ? 'border-success-500' : 'border-danger-500' }}">
                <dt class="text-sm text-gray-500">Address the shop uses</dt>
                <dd class="mt-1 font-mono text-base">{{ $resolved ?: '(none)' }}</dd>
            </div>
        </dl>

        <p class="mt-4 text-sm {{ $verdict === 'ok' ? '' : 'font-medium text-danger-600' }}">{{ $advice }}</p>
        <p class="mt-2 text-sm text-gray-500">Check: open any "what is my IP" site on this same device. It should match "Address the shop uses". Connection is {{ $secure ? 'HTTPS' : 'not HTTPS' }}.</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Headers that may carry the visitor's address</x-slot>

        <table class="w-full text-left text-sm">
            <tbody>
                @foreach ($headers as $name => $value)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-4 font-medium">{{ $name }}</td>
                        <td class="py-2 font-mono">{{ $value !== '' ? $value : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Settings in force (.env)</x-slot>

        <table class="w-full text-left text-sm">
            <tbody>
                @foreach ($settings as $name => $value)
                    <tr class="border-b border-gray-100 dark:border-white/5">
                        <td class="py-2 pr-4 font-medium">{{ $name }}</td>
                        <td class="py-2 font-mono">{{ $value }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
