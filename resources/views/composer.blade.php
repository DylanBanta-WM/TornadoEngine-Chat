{{-- The message box. $action is where it posts. --}}
<form method="POST" action="{{ $action }}" class="space-y-3">
    @csrf
    <label for="chat-text" class="sr-only">{{ __('Message') }}</label>
    <textarea id="chat-text" name="text" rows="3" required @disabled($running ?? false)
              x-on:keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $el.form.requestSubmit() }"
              class="block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-base focus:border-indigo-500 focus:ring-indigo-500">{{ old('text') }}</textarea>
    <x-input-error :messages="$errors->get('text')" />
    <div class="flex justify-end">
        <x-primary-button :disabled="$running ?? false">{{ __('Send') }}</x-primary-button>
    </div>
</form>
