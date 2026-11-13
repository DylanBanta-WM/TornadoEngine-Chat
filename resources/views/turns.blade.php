@php
    $markdown = '[&_p]:my-2 [&_ul]:my-2 [&_ul]:list-disc [&_ul]:ps-5 [&_ol]:my-2 [&_ol]:list-decimal [&_ol]:ps-5 [&_code]:font-mono [&_code]:text-sm [&_pre]:my-2 [&_pre]:overflow-x-auto [&_pre]:rounded-md [&_pre]:bg-gray-900 [&_pre]:p-3 [&_pre]:text-gray-100 [&_a]:text-indigo-600 [&_a]:underline [&_h1]:font-semibold [&_h2]:font-semibold [&_h3]:font-semibold [&_table]:block [&_table]:overflow-x-auto';
@endphp

@foreach ($turns as $turn)
    <div class="flex justify-end">
        <div class="max-w-[85%] whitespace-pre-wrap break-words rounded-lg bg-indigo-600 px-4 py-2 text-white">{{ $turn['text'] }}</div>
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
                    <p class="break-all font-mono text-xs text-gray-700 dark:text-gray-300">{{ app(\TornadoEngine\Broker\Broker::class)->display(\Illuminate\Support\Str::after((string) $detail, '/workspace/web/')) }}</p>
                @endif
                @if ($approval->tool === 'ExitPlanMode' && isset($approval->input['plan']))
                    <div class="break-words text-gray-900 dark:text-gray-100 {{ $markdown }}">{!! \Illuminate\Support\Str::markdown(app(\TornadoEngine\Broker\Broker::class)->display((string) $approval->input['plan']), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}</div>
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
