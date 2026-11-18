@php
    $markdown = '[&_p]:my-2 [&_ul]:my-2 [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:ps-5 [&_code]:font-mono [&_code]:text-sm [&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-gray-900 [&_pre]:p-3 [&_pre]:text-gray-100 [&_a]:text-indigo-600 [&_a]:underline [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_table]:block [&_table]:overflow-x-auto';
@endphp

@foreach ($turns as $turn)
    <div class="flex flex-col items-end gap-1.5">
        @php($images = array_values(array_filter($turn['files'] ?? [], fn ($file) => $file['image'])))
        @if ($images)
            <div class="flex max-w-[85%] flex-wrap justify-end gap-2">
                @foreach ($images as $file)
                    <button type="button" data-gallery="{{ $file['image'] }}" data-name="{{ $file['name'] }}" class="overflow-hidden rounded-2xl border border-gray-200 dark:border-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" aria-label="{{ __('Open :name', ['name' => $file['name']]) }}">
                        <img src="{{ $file['image'] }}" alt="{{ $file['name'] }}" loading="lazy" class="{{ count($images) === 1 ? 'max-h-64 max-w-full' : 'size-32 object-cover' }} block">
                    </button>
                @endforeach
            </div>
        @endif
        @foreach (array_filter($turn['files'] ?? [], fn ($file) => ! $file['image']) as $file)
            <span class="inline-flex max-w-[85%] items-center gap-1.5 rounded-xl border border-gray-200 dark:border-gray-700 px-3 py-1.5 text-sm text-gray-700 dark:text-gray-300">
                <svg class="size-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                <span class="truncate">{{ $file['name'] }}</span>
            </span>
        @endforeach
        <div class="max-w-[85%] whitespace-pre-wrap break-words rounded-3xl bg-gray-100 dark:bg-gray-800 px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $turn['text'] }}</div>
    </div>

    <div class="space-y-2">
        @foreach ($turn['items'] as $item)
            @if ($item['kind'] === 'text')
                <div class="break-words text-gray-900 dark:text-gray-100 {{ $markdown }}">{!! $item['html'] !!}</div>
            @else
                <p class="break-all font-mono text-xs text-gray-500 dark:text-gray-400">{{ $item['text'] }}</p>
            @endif
        @endforeach

        @foreach ($turn['approvals'] as $approval)
            <div class="rounded-md border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 px-4 py-3 space-y-2">
                <p class="font-medium text-amber-900 dark:text-amber-200">
                    @if ($approval->tool === 'ExitPlanMode')
                        {{ __('Start building this plan?') }}
                    @else
                        {{ $approval->tool }}
                    @endif
                </p>
                @php($detail = $approval->input['command'] ?? $approval->input['file_path'] ?? null)
                @if ($detail)
                    <p class="break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ (($asClaude ?? false) ? fn ($text) => $text : fn ($text) => app(\TornadoEngine\Broker\Broker::class)->display($text))(\Illuminate\Support\Str::after((string) $detail, '/workspace/web/')) }}</p>
                @endif
                @if ($approval->tool === 'ExitPlanMode' && isset($approval->input['plan']))
                    <div class="break-words text-gray-900 dark:text-gray-100 {{ $markdown }}">{!! \Illuminate\Support\Str::markdown((($asClaude ?? false) ? fn ($text) => $text : fn ($text) => app(\TornadoEngine\Broker\Broker::class)->display($text))((string) $approval->input['plan']), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
                @endif
                @if ($approval->status === 'pending')
                    <div class="flex justify-end gap-3">
                        @foreach ([0 => __('Deny'), 1 => __('Allow')] as $allow => $label)
                            <form method="POST" action="{{ route('chat.answer', [$conversation, $approval]) }}">
                                @csrf
                                <input type="hidden" name="allow" value="{{ $allow }}">
                                @if ($allow)
                                    <x-primary-button>{{ $label }}</x-primary-button>
                                @else
                                    <x-secondary-button type="submit">{{ $label }}</x-secondary-button>
                                @endif
                            </form>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-amber-900 dark:text-amber-200">{{ $approval->status === 'allowed' ? __('Allowed') : __('Denied') }}</p>
                @endif
            </div>
        @endforeach

        @if (in_array($turn['status'], ['queued', 'running'], true))
            <p class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"/><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"/></svg>
                {{ $turn['status'] === 'queued' ? __('Waiting for the harness…') : __('Working…') }}
            </p>
        @elseif ($turn['status'] === 'stopped')
            <p class="text-gray-500 dark:text-gray-400">{{ __('Stopped.') }}</p>
        @elseif ($turn['error'])
            <p class="rounded-md bg-red-50 dark:bg-red-900/30 px-4 py-3 text-red-800 dark:text-red-300">{{ $turn['error'] }}</p>
        @endif
    </div>
@endforeach
