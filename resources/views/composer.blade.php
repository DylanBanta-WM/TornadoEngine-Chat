{{-- The message box. $action is where it posts. With the Uploads module, files can be attached. --}}
@php($canUpload = class_exists(\TornadoEngine\Uploads\Uploads::class) && auth()->user()?->can('uploads.use') && \Illuminate\Support\Facades\Route::has('uploads.store'))

<form method="POST" action="{{ $action }}" class="mx-auto w-full max-w-3xl"
      x-data="{
          files: [],
          get busy() { return this.files.some(f => ! f.id && ! f.error) },
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
          pick(list) {
              for (const file of list) this.upload(file);
              this.$refs.files.value = '';
          },
          upload(file) {
              this.files.push({ key: Math.random(), name: file.name, progress: 0, id: null, share: 'text', image: /\.(png|jpe?g|gif|bmp|tiff?|webp)$/i.test(file.name), waiting: false, faces: null, error: null, url: null });
              const item = this.files[this.files.length - 1];
              if (file.size > {{ $canUpload ? \TornadoEngine\Uploads\Uploads::maxMb() : 0 }} * 1048576) { item.error = @js(__('Larger than :size MB', ['size' => $canUpload ? \TornadoEngine\Uploads\Uploads::maxMb() : 0])); return; }
              const data = new FormData();
              data.append('files[]', file);
              data.append('share', 'text');
              const xhr = new XMLHttpRequest();
              xhr.open('POST', @js($canUpload ? route('uploads.store') : ''));
              xhr.setRequestHeader('Accept', 'application/json');
              xhr.setRequestHeader('X-CSRF-TOKEN', @js(csrf_token()));
              xhr.upload.onprogress = e => { if (e.lengthComputable) item.progress = Math.round(e.loaded / e.total * 100) };
              xhr.onload = () => {
                  let response = {};
                  try { response = JSON.parse(xhr.responseText) } catch (e) {}
                  if (xhr.status === 200 && response.uploads) Object.assign(item, response.uploads[0]);
                  else item.error = response.errors ? Object.values(response.errors)[0][0] : (response.message || @js(__('Couldn\'t upload it.')));
              };
              xhr.onerror = () => item.error = @js(__('Couldn\'t upload it.'));
              xhr.send(data);
          },
          async share(item, share, confirm = false) {
              const response = await fetch(item.url.share, { method: 'POST', headers: this.headers, body: JSON.stringify({ share, confirm_faces: confirm }) });
              if (response.ok) Object.assign(item, await response.json());
          },
          remove(item) {
              if (item.url) fetch(item.url.destroy, { method: 'DELETE', headers: this.headers });
              this.files = this.files.filter(f => f !== item);
          },
      }">
    @csrf
    <template x-for="file in files.filter(f => f.id)" :key="file.key">
        <input type="hidden" name="uploads[]" :value="file.id">
    </template>

    <div class="rounded-3xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 p-2 shadow-sm focus-within:border-gray-400 dark:focus-within:border-gray-500">
        @if ($canUpload)
            <div x-show="files.length" x-cloak class="flex flex-wrap gap-2 p-1">
                <template x-for="file in files" :key="file.key">
                    <div class="w-full space-y-1.5 rounded-2xl border border-gray-200 dark:border-gray-700 px-3 py-2 text-sm sm:w-72">
                        <div class="flex items-center gap-2">
                            <span class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-gray-100" x-text="file.name"></span>
                            <span x-show="! file.id && ! file.error" class="text-gray-500 dark:text-gray-400" x-text="file.progress < 100 ? file.progress + '%' : @js(__('Reading…'))"></span>
                            <button type="button" x-on:click="remove(file)" class="-me-1 inline-flex size-8 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Remove') }}">
                                <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                            </button>
                        </div>
                        <select x-show="file.id" x-on:change="share(file, $event.target.value)" :aria-label="@js(__('What Claude gets'))"
                                class="block w-full min-h-11 rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="text" :selected="file.share === 'text'">{{ __('Filtered text') }}</option>
                            <option value="none" :selected="file.share === 'none'">{{ __('Nothing (student information)') }}</option>
                            <option value="image" x-show="file.image" :disabled="! file.image" :selected="file.share === 'image'">{{ __('Image too (no student information)') }}</option>
                        </select>
                        <div x-show="file.waiting" class="space-y-1.5 text-amber-800 dark:text-amber-300">
                            <p x-text="file.faces === null ? @js(__('Couldn\'t check it for faces. Claude gets only its text until you confirm.')) : (file.faces === 1 ? @js(__('A face was found. Claude gets only its text until you confirm.')) : file.faces + @js(' '.__('faces were found. Claude gets only its text until you confirm.')))"></p>
                            <button type="button" x-on:click="share(file, 'image', true)" class="min-h-11 font-medium underline">{{ __('Give Claude the image') }}</button>
                        </div>
                        <p x-show="file.error" class="text-red-700 dark:text-red-400" x-text="file.error"></p>
                    </div>
                </template>
            </div>
        @endif

        <div class="flex items-end gap-1">
            @if ($canUpload)
                <input type="file" multiple x-ref="files" class="hidden" x-on:change="pick($event.target.files)">
                <button type="button" x-on:click="$refs.files.click()" @disabled($running ?? false) aria-label="{{ __('Attach files') }}"
                        class="inline-flex size-11 shrink-0 items-center justify-center rounded-full text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-40">
                    <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13"/></svg>
                </button>
            @endif
            <label for="chat-text" class="sr-only">{{ __('Message') }}</label>
            <textarea id="chat-text" name="text" rows="1" required @disabled($running ?? false) placeholder="{{ __('Message') }}"
                      x-init="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                      x-on:input="$el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 200) + 'px'"
                      x-on:keydown.enter="if (! $event.shiftKey && ! window.matchMedia('(pointer: coarse)').matches) { $event.preventDefault(); if (! busy) $el.form.requestSubmit() }"
                      class="block max-h-[200px] min-h-11 min-w-0 flex-1 resize-none border-0 bg-transparent px-2 py-2.5 text-base text-gray-900 dark:text-gray-100 placeholder-gray-400 focus:ring-0">{{ old('text') }}</textarea>
            <button type="submit" @disabled($running ?? false) :disabled="busy || @js((bool) ($running ?? false))" aria-label="{{ __('Send') }}"
                    class="inline-flex size-11 shrink-0 items-center justify-center rounded-full bg-gray-900 dark:bg-gray-100 text-white dark:text-gray-900 hover:bg-gray-700 dark:hover:bg-white disabled:opacity-40">
                <svg class="size-5" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m0 0l-6 6m6-6l6 6"/></svg>
            </button>
        </div>
    </div>
    @if ($canUpload)
        <div x-show="files.length" x-cloak class="mt-2 px-3">@include('uploads::warning')</div>
    @endif
    <x-input-error :messages="$errors->get('text')" class="mt-2 px-3" />
</form>
