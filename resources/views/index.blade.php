<x-admin-layout :title="__('Chat')">
    <x-settings-card :title="__('New chat')">
        @include('chat::composer', ['action' => route('chat.start'), 'mode' => 'bypassPermissions'])
    </x-settings-card>

    @if ($conversations->isNotEmpty())
        <section class="bg-white dark:bg-gray-800 shadow-sm sm:rounded-lg">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                @foreach ($conversations as $conversation)
                    <li>
                        <a href="{{ route('chat.show', $conversation) }}" class="flex min-h-11 items-center justify-between gap-3 px-6 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50">
                            <span class="min-w-0 truncate text-gray-900 dark:text-gray-100">{{ $conversation->title }}</span>
                            <span class="shrink-0 text-xs text-gray-500 dark:text-gray-400">{{ $conversation->updated_at->diffForHumans(short: true) }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</x-admin-layout>
