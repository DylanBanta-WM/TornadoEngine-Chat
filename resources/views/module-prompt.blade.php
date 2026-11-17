{{-- What Claude knows about $module, on its settings page (and for modules without one, in Administration → Chat). --}}
@can('administration')
    @php($edited = \App\Models\Setting::get('chat.module_prompt.'.$module->package) !== null)
    <x-settings-card wide :title="__('What Claude knows about :name', ['name' => $module->name])">
        @if (session('chat_module_status') === $module->package)
            <p class="text-green-700 dark:text-green-400">{{ __('Saved.') }}</p>
        @endif
        <form method="POST" action="{{ route('chat.module-prompt') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="package" value="{{ $module->package }}">
            <label for="module-prompt-{{ \Illuminate\Support\Str::slug($module->package) }}" class="sr-only">{{ __('What Claude knows about :name', ['name' => $module->name]) }}</label>
            <textarea id="module-prompt-{{ \Illuminate\Support\Str::slug($module->package) }}" name="prompt" rows="3" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ \TornadoEngine\Chat\Chat::modulePrompt($module) }}</textarea>
            <div class="flex flex-wrap justify-end gap-3">
                @if ($edited)
                    <x-secondary-button type="submit" name="restore" value="1">{{ __('Restore default') }}</x-secondary-button>
                @endif
                <x-primary-button>{{ __('Save') }}</x-primary-button>
            </div>
        </form>
    </x-settings-card>
@endcan
