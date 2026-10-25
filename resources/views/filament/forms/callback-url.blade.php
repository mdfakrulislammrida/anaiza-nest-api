{{-- The address a sign-in provider sends the browser back to. Worked out by the shop (the API's own address plus the callback route), never typed. --}}
<div
    x-data="{ copied: false, url: @js($url) }"
    class="rounded-lg border border-gray-200 p-4 text-sm dark:border-white/10"
>
    <p class="font-medium text-gray-950 dark:text-white">{{ $provider }} redirect address (read only)</p>

    <div class="mt-2 flex items-center gap-2">
        <input
            type="text"
            readonly
            value="{{ $url }}"
            aria-label="{{ $provider }} redirect address"
            x-on:focus="$el.select()"
            class="block w-full rounded-lg border-gray-300 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-950 dark:border-white/10 dark:bg-white/5 dark:text-white"
        >
        <button
            type="button"
            x-on:click="navigator.clipboard.writeText(url).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
            class="shrink-0 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-950 hover:bg-gray-50 dark:border-white/10 dark:text-white dark:hover:bg-white/5"
        >
            <span x-show="! copied">Copy</span>
            <span x-show="copied" x-cloak>Copied</span>
        </button>
    </div>

    <p class="mt-2 text-gray-600 dark:text-gray-400">Add this exact URL in {{ $where }}.</p>

    @if ($provider === 'Google')
        <p class="mt-1 text-gray-600 dark:text-gray-400">"Authorised JavaScript origins" is not needed: the sign-in is a redirect, not a Google button on the page.</p>
    @endif

    @if (! empty($problem))
        <p class="mt-2 font-medium text-danger-600 dark:text-danger-400">{{ $problem }}</p>
    @endif
</div>
