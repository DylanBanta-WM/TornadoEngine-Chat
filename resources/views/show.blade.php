<x-admin-layout :title="$conversation->title">
    <div class="flex items-center justify-between gap-3 px-4 sm:px-0">
        <a href="{{ route('chat.index') }}" class="inline-flex min-h-11 items-center font-medium text-indigo-600 dark:text-indigo-400 underline">{{ __('All chats') }}</a>
        @if ($running)
            <form method="POST" action="{{ route('chat.stop', $conversation) }}">
                @csrf
                <x-secondary-button type="submit">{{ __('Stop') }}</x-secondary-button>
            </form>
        @endif
    </div>

    <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg p-4 sm:p-6"
             x-data="{
                running: @js($running),
                async refresh() {
                    const response = await fetch(@js(route('chat.turns', $conversation)), { headers: { Accept: 'application/json' } });
                    if (! response.ok) return;
                    const data = await response.json();
                    this.$refs.turns.innerHTML = data.html;
                    if (! data.running) location.reload();
                },
             }"
             x-init="if (running) setInterval(() => refresh(), 2000)">
        <div x-ref="turns" class="space-y-4 text-sm">
            @include('chat::turns')
        </div>
    </section>

    <x-settings-card :title="__('Reply')">
        @include('chat::composer', ['action' => route('chat.send', $conversation), 'mode' => $conversation->mode, 'running' => $running])
    </x-settings-card>
</x-admin-layout>
