{{-- The message box. $action is where it posts. --}}
<form method="POST" action="{{ $action }}" class="mx-auto w-full max-w-3xl">
    @csrf
    <div class="flex items-end gap-2 rounded-3xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 p-2 shadow-sm focus-within:border-gray-400 dark:focus-within:border-gray-500">
        <label for="chat-text" class="sr-only">{{ __('Message') }}</label>
        <textarea id="chat-text" name="text" rows="1" required @disabled($running ?? false) placeholder="{{ __('Message') }}"
                  x-data x-init="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                  x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                  x-on:keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); $el.form.requestSubmit() }"
                  class="block max-h-[200px] min-h-11 flex-1 resize-none border-0 bg-transparent px-3 py-2.5 text-base text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-0">{{ old('text') }}</textarea>
        <button type="submit" @disabled($running ?? false) aria-label="{{ __('Send') }}"
                class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-white disabled:opacity-40">
            <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-6 6m6-6l6 6"/></svg>
        </button>
    </div>
    <x-input-error :messages="$errors->get('text')" class="mt-2 px-3" />
</form>
