{{-- The message box. $action is where it posts. With the Uploads module, files can be attached. --}}
@php($canUpload = class_exists(\TornadoEngine\Uploads\Uploads::class) && auth()->user()?->can('uploads.use') && \Illuminate\Support\Facades\Route::has('uploads.store'))

<form method="POST" action="{{ $action }}" class="mx-auto w-full max-w-3xl"
      x-data="{
          files: [],
          pending: [],
          reviewing: null,
          get busy() { return this.files.some(f => (! f.id && ! f.error) || f.waiting) },
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
          pick(list) {
              this.pending = [...list];
              this.$refs.files.value = '';
              if (this.pending.length) this.$dispatch('open-modal', 'chat-files');
          },
          answer(student) {
              for (const file of this.pending) this.upload(file, student);
              this.pending = [];
              this.$dispatch('close-modal', 'chat-files');
          },
          upload(file, student) {
              this.files.push({ key: Math.random(), name: file.name, progress: 0, id: null, student, image: /\.(png|jpe?g|gif|bmp|tiff?|webp)$/i.test(file.name), text: true, waiting: false, faces: 0, logos: 0, covered: 0, preview: null, error: null, url: null });
              const item = this.files[this.files.length - 1];
              if (file.size > {{ $canUpload ? \TornadoEngine\Uploads\Uploads::maxMb() : 0 }} * 1048576) { item.error = @js(__('Larger than :size MB', ['size' => $canUpload ? \TornadoEngine\Uploads\Uploads::maxMb() : 0])); return; }
              const data = new FormData();
              data.append('files[]', file);
              data.append('student', student ? 1 : 0);
              const xhr = new XMLHttpRequest();
              xhr.open('POST', @js($canUpload ? route('uploads.store') : ''));
              xhr.setRequestHeader('Accept', 'application/json');
              xhr.setRequestHeader('X-CSRF-TOKEN', @js(csrf_token()));
              xhr.upload.onprogress = e => { if (e.lengthComputable) item.progress = Math.round(e.loaded / e.total * 100) };
              xhr.onload = () => {
                  let response = {};
                  try { response = JSON.parse(xhr.responseText) } catch (e) {}
                  if (xhr.status === 200 && response.uploads) {
                      Object.assign(item, response.uploads[0]);
                      if (item.waiting) this.review(item);
                  } else item.error = response.errors ? Object.values(response.errors)[0][0] : (response.message || @js(__('Couldn\'t upload it.')));
              };
              xhr.onerror = () => item.error = @js(__('Couldn\'t upload it.'));
              xhr.send(data);
          },
          review(item) {
              this.reviewing = item;
              this.boxes = null;
              this.drawing = null;
              this.$dispatch('open-modal', 'chat-review');
          },
          // The red boxes, in percent of the image, once it has loaded: found ones the uploader can remove, and their own.
          boxes: null,
          drawing: null,
          size: null,
          loaded(img) {
              this.size = [img.naturalWidth, img.naturalHeight];
              if (! this.reviewing.boxes) return; // Uploads before 0.6 draws them into the image
              this.boxes = this.reviewing.boxes.map(([x, y, w, h]) => ({ key: Math.random(), x: x / this.size[0] * 100, y: y / this.size[1] * 100, w: w / this.size[0] * 100, h: h / this.size[1] * 100 }));
          },
          point(event) {
              const rect = this.$refs.canvas.getBoundingClientRect();
              return [Math.min(100, Math.max(0, (event.clientX - rect.left) / rect.width * 100)), Math.min(100, Math.max(0, (event.clientY - rect.top) / rect.height * 100))];
          },
          start(event) {
              if (! this.boxes || event.button > 0) return;
              event.currentTarget.setPointerCapture(event.pointerId);
              const [x, y] = this.point(event);
              this.drawing = { key: Math.random(), from: [x, y], x, y, w: 0, h: 0 };
          },
          move(event) {
              if (! this.drawing) return;
              const [x, y] = this.point(event);
              const [fx, fy] = this.drawing.from;
              Object.assign(this.drawing, { x: Math.min(x, fx), y: Math.min(y, fy), w: Math.abs(x - fx), h: Math.abs(y - fy) });
          },
          end() {
              if (this.drawing && this.drawing.w > 1 && this.drawing.h > 1) this.boxes.push(this.drawing);
              this.drawing = null;
          },
          pixels() {
              return (this.boxes || []).map(b => [Math.round(b.x / 100 * this.size[0]), Math.round(b.y / 100 * this.size[1]), Math.round(b.w / 100 * this.size[0]), Math.round(b.h / 100 * this.size[1])]);
          },
          abort() {
              const item = this.reviewing;
              this.reviewing = null;
              this.$dispatch('close-modal', 'chat-review');
              this.remove(item);
              const next = this.files.find(f => f.waiting);
              if (next) this.$nextTick(() => this.review(next));
          },
          async decide(cover) {
              const item = this.reviewing;
              const body = cover && this.boxes ? { cover: this.boxes.length > 0, boxes: this.pixels() } : { cover };
              const response = await fetch(item.url.review, { method: 'POST', headers: this.headers, body: JSON.stringify(body) });
              if (response.ok) Object.assign(item, await response.json());
              this.reviewing = null;
              this.$dispatch('close-modal', 'chat-review');
              const next = this.files.find(f => f.waiting);
              if (next) this.$nextTick(() => this.review(next));
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
                    <div class="flex w-full gap-3 rounded-2xl border border-gray-200 dark:border-gray-700 p-2 text-sm sm:w-80">
                        <template x-if="file.preview">
                            <img :src="file.preview" alt="" class="size-14 shrink-0 rounded-lg border border-gray-200 dark:border-gray-700 object-cover">
                        </template>
                        <div class="min-w-0 flex-1 space-y-0.5 py-0.5">
                            <div class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate font-medium text-gray-900 dark:text-gray-100" x-text="file.name"></span>
                                <span x-show="! file.id && ! file.error" class="inline-flex items-center gap-1.5 text-gray-500 dark:text-gray-400">
                                    <svg x-show="file.progress >= 100" class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"/><path fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" class="opacity-75"/></svg>
                                    <span x-text="file.progress < 100 ? file.progress + '%' : @js(__('Reading…'))"></span>
                                </span>
                                <button type="button" x-on:click="remove(file)" class="-my-1 -me-1 inline-flex size-8 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="{{ __('Remove') }}">
                                    <svg class="size-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
                                </button>
                            </div>
                            <p x-show="file.id" class="text-gray-600 dark:text-gray-400" x-text="file.student ? @js(__('Student information')) : @js(__('No student information'))"></p>
                            <p x-show="file.id && file.covered" class="text-gray-600 dark:text-gray-400" x-text="file.covered === 1 ? @js(__('1 area covered')) : file.covered + @js(' '.__('areas covered'))"></p>
                            <button type="button" x-show="file.waiting" x-on:click="review(file)" class="min-h-11 font-medium text-amber-800 dark:text-amber-300 underline">{{ __('Check what was found') }}</button>
                            <p x-show="file.id && ! file.image && ! file.text" class="text-amber-800 dark:text-amber-300">{{ __('No text found, so Claude won\'t see it.') }}</p>
                            <p x-show="file.error" class="text-red-700 dark:text-red-400" x-text="file.error"></p>
                        </div>
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
            <textarea id="chat-text" name="text" rows="1" required :required="! files.some(f => f.id)" @disabled($running ?? false) placeholder="{{ __('Message') }}"
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
        <x-modal name="chat-files" maxWidth="md">
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100" x-text="pending.length > 1 ? @js(__('Do these files contain student information?')) : @js(__('Does this file contain student information?'))"></h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Files with student information go through the broker, which tries to remove it before Claude reads them. It can miss some, especially in images, so consider removing student information before you upload.') }}</p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <button type="button" x-on:click="pending = []; $dispatch('close-modal', 'chat-files')" class="min-h-11 font-medium text-gray-600 dark:text-gray-400 underline">{{ __('Cancel') }}</button>
                    <div class="flex gap-3">
                        <x-secondary-button type="button" x-on:click="answer(true)">{{ __('Yes') }}</x-secondary-button>
                        <x-primary-button type="button" x-on:click="answer(false)">{{ __('No') }}</x-primary-button>
                    </div>
                </div>
            </div>
        </x-modal>

        <x-modal name="chat-review" maxWidth="2xl">
            <div class="space-y-4 p-6">
                <h2 class="text-lg font-medium text-gray-900 dark:text-gray-100"
                    x-text="! reviewing ? '' : (reviewing.faces && reviewing.logos ? @js(__('Are these students\' faces or blocked logos?')) : (reviewing.faces ? (reviewing.faces === 1 ? @js(__('Is this a student\'s face?')) : @js(__('Are these students\' faces?'))) : (reviewing.logos > 1 ? @js(__('Are these blocked logos?')) : @js(__('Is this a blocked logo?')))))"></h2>
                <template x-if="reviewing && reviewing.preview">
                    <div class="flex justify-center">
                        <div x-ref="canvas" class="relative inline-block cursor-crosshair touch-none select-none"
                             x-on:pointerdown="start($event)" x-on:pointermove="move($event)" x-on:pointerup="end()" x-on:pointercancel="drawing = null">
                            <img :src="reviewing.preview" alt="" draggable="false" x-on:load="loaded($el)" class="block max-h-[60vh] max-w-full rounded-md border border-gray-200 dark:border-gray-700">
                            <template x-for="box in (boxes || [])" :key="box.key">
                                <div class="absolute border-[3px] border-red-600" :style="`left:${box.x}%;top:${box.y}%;width:${box.w}%;height:${box.h}%`">
                                    <button type="button" x-on:pointerdown.stop x-on:click="boxes = boxes.filter(b => b !== box)" aria-label="{{ __('Remove this box') }}"
                                            class="absolute -right-3 -top-3 flex size-7 items-center justify-center rounded-full bg-red-600 text-sm font-bold leading-none text-white shadow hover:bg-red-700">&times;</button>
                                </div>
                            </template>
                            <div x-show="drawing" class="absolute border-[3px] border-dashed border-red-600" :style="drawing && `left:${drawing.x}%;top:${drawing.y}%;width:${drawing.w}%;height:${drawing.h}%`"></div>
                        </div>
                    </div>
                </template>
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('Red boxes show what was found. Drag to add a box; × removes one. Black bars cover text the broker replaces.') }}</p>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <x-danger-button type="button" x-on:click="abort()">{{ __('Don\'t send to Claude') }}</x-danger-button>
                    <div class="flex flex-wrap gap-3">
                        <x-secondary-button type="button" x-on:click="decide(false)">{{ __('No, leave it') }}</x-secondary-button>
                        <x-primary-button type="button" x-on:click="decide(true)">{{ __('Yes, cover them') }}</x-primary-button>
                    </div>
                </div>
            </div>
        </x-modal>
    @endif
    <x-input-error :messages="$errors->get('text')" class="mt-2 px-3" />
</form>
