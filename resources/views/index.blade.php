<x-admin-layout :title="__('Chat')">
    <x-settings-card :title="__('New chat')">
        @include('chat::composer', ['action' => route('chat.start')])
    </x-settings-card>

    @if ($conversations->isNotEmpty())
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <h3 class="px-6 pt-4 font-semibold text-gray-900 dark:text-gray-100">{{ __('History') }}</h3>
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($conversations as $conversation)
                    <li>
                        <a href="{{ route('chat.show', $conversation) }}" class="flex min-h-11 items-center justify-between gap-3 px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <span class="flex min-w-0 items-center gap-2">
                                <span class="truncate text-gray-900 dark:text-gray-100">{{ $conversation->title }}</span>
                                @if ($conversation->saved)
                                    <span class="shrink-0 rounded bg-indigo-50 dark:bg-indigo-900/50 px-1.5 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('Saved') }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $conversation->updated_at->diffForHumans(short: true) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin-layout>
