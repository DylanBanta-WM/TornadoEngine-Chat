{{-- Chat: history on the left, the conversation in the middle, the message box at the bottom. --}}
@php
    $icon = 'size-5';
    // In "What Claude sees", titles (from the first message) go through the broker too.
    $title = fn ($chat) => ($asClaude ?? false) ? app(\TornadoEngine\Broker\Broker::class)->filter((string) $chat->title) : $chat->title;
    // [short label, tooltip] for how long an unsaved chat has left.
    $expiryOf = function ($chat) {
        $hoursLeft = max(1, (int) ceil(now()->diffInSeconds($chat->updated_at->copy()->addDays(\TornadoEngine\Chat\Models\Conversation::KEEP_DAYS), false) / 3600));
        $daysLeft = (int) ceil($hoursLeft / 24);

        return $hoursLeft > 24
            ? [$daysLeft.'d', trans_choice('This chat will expire in :count day unless saved.|This chat will expire in :count days unless saved.', $daysLeft)]
            : [$hoursLeft.'h', trans_choice('This chat will expire in :count hour unless saved.|This chat will expire in :count hours unless saved.', $hoursLeft)];
    };
@endphp

<x-app-layout fill>
    <div class="flex min-h-0 flex-1 bg-white dark:bg-gray-900" x-data="{
             history: false,
             gallery: [],
             shown: null,
             open(event) {
                 const button = event.target.closest('[data-gallery]');
                 if (! button) return;
                 this.gallery = [...this.$refs.turns.querySelectorAll('[data-gallery]')].map(b => ({ src: b.dataset.gallery, name: b.dataset.name }));
                 this.shown = this.gallery.findIndex(image => image.src === button.dataset.gallery);
             },
             step(by) { if (this.shown !== null) this.shown = (this.shown + by + this.gallery.length) % this.gallery.length },
         }"
         x-on:keydown.escape.window="shown = null" x-on:keydown.arrow-left.window="step(-1)" x-on:keydown.arrow-right.window="step(1)">
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
                        <li x-data="{ editing: false }" class="group flex items-center">
                            <a x-show="! editing" href="{{ route('chat.show', $item) }}" @if ($conversation?->is($item)) aria-current="page" @endif
                               @class(['flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-lg px-3 text-sm', 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-gray-100' => $conversation?->is($item), 'text-gray-700 dark:text-gray-300 hover:bg-gray-200/70 dark:hover:bg-gray-700/70' => ! $conversation?->is($item)])>
                                <span class="min-w-0 flex-1 truncate">{{ $title($item) }}</span>
                                @unless ($item->saved)
                                    @php($left = $expiryOf($item))
                                    <span class="inline-flex shrink-0 items-center gap-0.5 text-xs text-gray-500 dark:text-gray-400" title="{{ $left[1] }}" aria-label="{{ $left[1] }}">
                                        <svg class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>{{ $left[0] }}
                                    </span>
                                @endunless
                                @if ($item->saved)
                                    <svg class="size-4 shrink-0 text-indigo-600 dark:text-indigo-400" fill="currentColor" viewBox="0 0 24 24" aria-label="{{ __('Saved') }}"><path d="M6 3h12a1 1 0 011 1v17l-7-4-7 4V4a1 1 0 011-1z"/></svg>
                                @endif
                            </a>
                            @unless ($asClaude ?? false)
                            {{-- Not in "What Claude sees": renaming there would show (and save) the real title. --}}
                            <button type="button" x-show="! editing" x-on:click="editing = true; $nextTick(() => $refs.name.select())"
                                    class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-200/70 dark:hover:bg-gray-700/70 md:opacity-0 md:group-hover:opacity-100 focus:opacity-100"
                                    aria-label="{{ __('Rename') }}" title="{{ __('Rename') }}">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            </button>
                            <form x-show="editing" x-cloak method="POST" action="{{ route('chat.rename', $item) }}" class="flex min-h-11 min-w-0 flex-1 items-center">
                                @csrf
                                <input x-ref="name" name="title" value="{{ $item->title }}" maxlength="60" required
                                       x-on:keydown.escape="editing = false" x-on:blur="editing = false"
                                       aria-label="{{ __('Chat name') }}"
                                       class="min-w-0 flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-1 text-sm text-gray-900 dark:text-gray-100">
                            </form>
                            @endunless
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
                        const response = await fetch(@js($conversation ? route('chat.turns', [$conversation, 'claude' => ($asClaude ?? false) ? 1 : null]) : ''), { headers: { Accept: 'application/json' } });
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
                <div x-data="{ editing: false }" class="flex min-w-0 flex-1 items-center gap-1">
                    <h1 x-show="! editing" class="min-w-0 truncate font-medium text-gray-900 dark:text-gray-100">{{ $conversation ? $title($conversation) : __('New chat') }}</h1>
                    @if ($conversation && ! ($asClaude ?? false))
                        <button type="button" x-show="! editing" x-on:click="editing = true; $nextTick(() => $refs.name.select())"
                                class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-gray-500 dark:text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-800"
                                aria-label="{{ __('Rename') }}" title="{{ __('Rename') }}">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                        </button>
                        <form x-show="editing" x-cloak method="POST" action="{{ route('chat.rename', $conversation) }}" class="flex min-w-0 flex-1">
                            @csrf
                            <input x-ref="name" name="title" value="{{ $conversation->title }}" maxlength="60" required
                                   x-on:keydown.escape="editing = false" x-on:blur="editing = false"
                                   aria-label="{{ __('Chat name') }}"
                                   class="min-w-0 flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-900 px-2 py-1 text-gray-900 dark:text-gray-100">
                        </form>
                    @endif
                </div>
                @if ($asClaude ?? false)
                    <span class="shrink-0 rounded bg-indigo-50 dark:bg-indigo-900/50 px-2 py-0.5 text-xs font-medium text-indigo-700 dark:text-indigo-300">{{ __('What Claude sees') }}</span>
                @endif
                @if ($conversation)
                    @if ($running)
                        <form method="POST" action="{{ route('chat.stop', $conversation) }}">
                            @csrf
                            <button class="inline-flex min-h-11 items-center rounded-lg px-3 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800">{{ __('Stop') }}</button>
                        </form>
                    @endif
                    <a href="{{ route('chat.show', [$conversation, 'claude' => ($asClaude ?? false) ? null : 1]) }}"
                       class="inline-flex size-11 items-center justify-center rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 {{ ($asClaude ?? false) ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-500 dark:text-gray-400' }}"
                       aria-label="{{ ($asClaude ?? false) ? __('Show names') : __('What Claude sees') }}" title="{{ ($asClaude ?? false) ? __('Show names') : __('What Claude sees') }}">
                        <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </a>

                    @unless ($conversation->saved)
                        @php($expiry = $expiryOf($conversation))
                        <span class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-lg px-2 text-xs font-medium text-gray-500 dark:text-gray-400" tabindex="0" role="img" title="{{ $expiry[1] }}" aria-label="{{ $expiry[1] }}">
                            <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 7v5l3 2"/></svg>
                            {{ $expiry[0] }}
                        </span>
                    @endunless

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
                    <div x-ref="turns" x-on:click="open($event)" class="mx-auto w-full max-w-3xl space-y-6 px-4 py-6 text-[15px] leading-relaxed">
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

        {{-- Images in the conversation, full size --}}
        <div x-show="shown !== null" x-cloak x-transition.opacity class="fixed inset-0 z-50 flex flex-col bg-gray-950/95" role="dialog" aria-modal="true" x-on:click.self="shown = null">
            <div class="flex items-center justify-between gap-3 px-4 py-3 text-gray-100">
                <p class="truncate text-sm" x-text="shown !== null ? gallery[shown].name + (gallery.length > 1 ? ' · ' + (shown + 1) + ' / ' + gallery.length : '') : ''"></p>
                <button type="button" x-on:click="shown = null" class="flex size-11 items-center justify-center rounded-full hover:bg-white/10" aria-label="{{ __('Close') }}">
                    <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="relative flex min-h-0 flex-1 items-center justify-center px-4 pb-4" x-on:click.self="shown = null">
                <template x-if="shown !== null">
                    <img :src="gallery[shown].src" :alt="gallery[shown].name" class="max-h-full max-w-full rounded-md object-contain">
                </template>
                <template x-if="gallery.length > 1">
                    <div>
                        <button type="button" x-on:click="step(-1)" class="absolute left-3 top-1/2 flex size-12 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70" aria-label="{{ __('Previous') }}">
                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                        </button>
                        <button type="button" x-on:click="step(1)" class="absolute right-3 top-1/2 flex size-12 -translate-y-1/2 items-center justify-center rounded-full bg-black/50 text-white hover:bg-black/70" aria-label="{{ __('Next') }}">
                            <svg class="{{ $icon }}" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                        </button>
                    </div>
                </template>
            </div>
        </div>
    </div>
</x-app-layout>
