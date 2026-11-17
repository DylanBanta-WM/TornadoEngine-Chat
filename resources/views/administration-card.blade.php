@php($custom = \App\Models\Setting::get('chat.system_prompt') !== null)

<x-settings-card wide :title="__('System prompt')">
    @if (session('chat_status'))
        <p class="text-green-700 dark:text-green-400">{{ session('chat_status') }}</p>
    @endif
    <form method="POST" action="{{ route('chat.prompt') }}" class="space-y-3">
        @csrf
        <label for="chat-prompt" class="sr-only">{{ __('System prompt') }}</label>
        <textarea id="chat-prompt" name="prompt" rows="24" class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('prompt', \TornadoEngine\Chat\Chat::promptTemplate()) }}</textarea>
        <p class="text-gray-600 dark:text-gray-400">{!! __(':modules is replaced with the installed modules, :vendor with the package name for your district.', ['modules' => '<code class="font-mono">{modules}</code>', 'vendor' => '<code class="font-mono">{vendor}</code>']) !!}</p>
        <x-input-error :messages="$errors->get('prompt')" />
        <div class="flex flex-wrap justify-end gap-3">
            @if ($custom)
                <x-secondary-button type="submit" name="restore" value="1" formnovalidate>{{ __('Restore default') }}</x-secondary-button>
            @endif
            <x-primary-button>{{ __('Save') }}</x-primary-button>
        </div>
    </form>
</x-settings-card>

{{-- Modules without a settings page of their own. --}}
@foreach (array_filter(app(\App\Modules\ModuleRegistry::class)->compatible(), fn ($module) => $module->settings === null) as $module)
    @include('chat::module-prompt', ['module' => $module])
@endforeach
