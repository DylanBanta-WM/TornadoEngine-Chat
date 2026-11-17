{{-- Chat: history on the left, the conversation in the middle, the message box at the bottom. --}}
@php
    $icon = 'size-5';
@endphp

<x-app-layout fill>
    <div class="flex min-h-0 flex-1 bg-white dark:bg-gray-900" x-data="{ history: false }">
        {{-- History --}}
        <div x-show="history" x-cloak x-on:click="history = false" class="fixed inset-0 z-30 bg-gray-900/40 md:hidden"></div>
        <aside :class="{ '-translate-x-full': ! history }"
               class="-translate-x-full fixed inset-y-0 start-0 z-40 flex w-72 flex-col bg-gray-50 dark:bg-gray-800 transition-transform md:static md:z-auto md:w-64 md:translate-x-0 md:border-e md:border-gray-200 md:dark:border-gray-700">
            <div class="p-3">
                <a href="{{ route('chat.index') }}" class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium text-gray-900 dark:text-gray-100 hover:bg-gray-200/70 dark:hover:bg-gray-700">
                    <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                    {{ __('New chat') }}
                </a>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 pb-3" aria-label="{{ __('History') }}">
                @if ($conversations->isNotEmpty())
                    <p class="px-3 pb-1 pt-2 text-xs font-semibold text-gray-500 dark:text-gray-400">{{ __('History') }}</p>
                @endif
                <ul class="space-y-0.5">
                    @foreach ($conversations as $item)
                        <li>
                            <a href="{{ route('chat.show', $item) }}" @if ($conversation?->is($item)) aria-current="page" @endif
                               @class(['flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm', 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-100' => $conversation?->is($item), 'text-gray-700 dark:text-gray-300 hover:bg-gray-200/70 dark:hover:bg-gray-700/70' => ! $conversation?->is($item)])>
                                <span class="min-w-0 flex-1 truncate">{{ $item->title }}</span>
                                @if ($item->saved)
                                    <svg class="size-4 shrink-0 text-indigo-600 dark:text-indigo-400" fill="currentColor" viewBox="0 0 24 24" aria-label="{{ __('Saved') }}"><path d="M6 3h12a1 1 0 011 1v17l-7-4-7 4V4a1 1 0 011-1z"/></svg>
                                @endif
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        </aside>

        {{-- Conversation --}}
        <section class="flex min-w-0 flex-1 flex-col"
                 x-data="{
                    running: @js($running),
                    bottom() { this.$refs.scroll.scrollTop = this.$refs.scroll.scrollHeight },
                    async refresh() {
                        const response = await fetch(@js($conversation ? route('chat.turns', $conversation) : ''), { headers: { Accept: 'application/json' } });
                        if (! response.ok) return;
                        const data = await response.json();
                        const atBottom = this.$refs.scroll.scrollHeight - this.$refs.scroll.scrollTop - this.$refs.scroll.clientHeight < 80;
                        this.$refs.turns.innerHTML = data.html;
                        if (atBottom) this.$nextTick(() => this.bottom());
                        if (! data.running) location.reload();
                    },
                 }"
                 x-init="$nextTick(() => bottom()); if (running) setInterval(() => refresh(), 2000)">
            <header class="flex min-h-14 items-center gap-2 border-b border-gray-200 dark:border-gray-700 px-3">
                <button type="button" x-on:click="history = true" class="inline-flex size-11 items-center justify-center rounded-lg text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 md:hidden" aria-label="{{ __('History') }}">
                    <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h10"/></svg>
                </button>
                <h1 class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-gray-100">{{ $conversation?->title ?? __('New chat') }}</h1>
                @if ($conversation)
                    @if ($running)
                        <form method="POST" action="{{ route('chat.stop', $conversation) }}">
                            @csrf
                            <button class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">{{ __('Stop') }}</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('chat.save', $conversation) }}">
                        @csrf
                        <button class="inline-flex size-11 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 {{ $conversation->saved ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500 dark:text-gray-400' }}"
                                aria-label="{{ $conversation->saved ? __('Unsave') : __('Save') }}" title="{{ $conversation->saved ? __('Unsave') : __('Save') }}">
                            <svg class="{{ $icon }}" viewBox="0 0 24 24" @if ($conversation->saved) fill="currentColor" @else fill="none" stroke="currentColor" stroke-width="2" @endif aria-hidden="true"><path stroke-linejoin="round" d="M6 3h12a1 1 0 011 1v17l-7-4-7 4V4a1 1 0 011-1z"/></svg>
                        </button>
                    </form>
                @endif
            </header>

            <div x-ref="scroll" class="flex-1 overflow-y-auto">
                @if ($conversation)
                    <div x-ref="turns" class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 text-[15px] leading-relaxed">
                        @include('chat::turns')
                    </div>
                @else
                    <div class="flex h-full items-center justify-center px-4">
                        <h2 class="text-center text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ __('What do you need?') }}</h2>
                    </div>
                @endif
            </div>

            <div class="px-4 pb-4 pt-2">
                @include('chat::composer', ['action' => $conversation ? route('chat.send', $conversation) : route('chat.start'), 'running' => $running])
            </div>
        </section>
    </div>
</x-app-layout>
