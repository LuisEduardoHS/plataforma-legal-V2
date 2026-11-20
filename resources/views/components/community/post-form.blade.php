@props(['post' => null, 'categories', 'specialties', 'tags'])
@php
    $types = array_filter(config('attachments.types'), fn ($type) => in_array($type['category'], ['image', 'video', 'document'], true));
    $uploadRules = ['types' => $types, 'maxBytes' => config('attachments.max_bytes'), 'maxCount' => config('community.max_attachments')];
    $tagError = $errors->has('tag_ids') || $errors->has('tag_ids.*');
    $attachmentError = $errors->has('attachment_ids') || $errors->has('attachment_ids.*');
    $uploadError = $errors->has('uploads') || $errors->has('uploads.*') || $errors->has('file');
    $authorName = auth()->user()?->profile?->display_name ?? auth()->user()?->name ?? 'Usuario';
    $authorInitial = mb_strtoupper(mb_substr(trim($authorName), 0, 1));
@endphp
<form method="POST" action="{{ $post ? route('community.posts.update', $post['id']) : route('community.posts.store') }}" enctype="multipart/form-data" data-post-form data-upload-rules="{{ Illuminate\Support\Js::encode($uploadRules) }}" class="flex min-h-0 flex-1 flex-col">
    @csrf @if ($post) @method('PUT')<input type="hidden" name="version" value="{{ old('version', $post['version']) }}">@endif
    <input type="hidden" name="replace_tags" value="1"><input type="hidden" name="replace_attachments" value="1">
    <div class="community-create-content flex-1 overflow-y-auto !p-5">
        <div class="grid grid-cols-1 items-start gap-5 md:grid-cols-[minmax(0,1fr)_minmax(0,300px)] has-[[data-upload-list]:empty]:md:grid-cols-1">
            <div class="min-w-0 space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <x-community.avatar :initials="$authorInitial" class="!size-10 !text-xs" />
                        <div class="min-w-0">
                            <strong class="block truncate text-sm font-semibold text-text dark:text-text-dark">{{ $authorName }}</strong>
                            <div class="mt-0.5 flex items-center gap-1.5">
                                <label for="community-visibility" class="community-sr-only">Visibilidad</label>
                                <span class="relative inline-flex items-center">
                                    <select name="visibility" id="community-visibility" required class="!mt-0 !min-h-6 !w-auto appearance-none !rounded-full !border-0 !bg-accent/10 !py-0.5 !pl-2.5 !pr-6 !text-[11px] !font-semibold !text-accent dark:!bg-selected-dark dark:!text-accent-text-dark" @if ($errors->has('visibility')) aria-invalid="true" aria-describedby="community-visibility-error" @endif>
                                        <option value="private" @selected(old('visibility', $post['visibility'] ?? 'private') === 'private')>Privada (solo tú)</option>
                                        <option value="public" @selected(old('visibility', $post['visibility'] ?? 'private') === 'public')>Pública (toda la comunidad)</option>
                                    </select>
                                    <x-lucide-chevron-down class="pointer-events-none absolute right-2 !size-3 text-accent dark:text-accent-text-dark" aria-hidden="true" />
                                </span>
                            </div>
                            <x-community.field-error field="visibility" id="community-visibility-error" />
                        </div>
                    </div>
                </div>

                <div>
                    <label class="community-sr-only" for="community-draft">Contenido de la publicación</label>
                    <textarea id="community-draft" name="content" rows="5" maxlength="{{ config('community.post_max_length') }}" placeholder="¿Qué quieres compartir con la comunidad?" class="!mt-0 !min-h-32 !resize-none !border-0 !bg-transparent !p-0 !text-[15.5px] !leading-relaxed !shadow-none focus-visible:!outline-none" aria-describedby="community-draft-count{{ $errors->has('content') ? ' community-content-error' : '' }}" @if ($errors->has('content')) aria-invalid="true" @endif>{{ old('content', $post['content'] ?? '') }}</textarea>
                    <div class="mt-1 flex items-center justify-between gap-2">
                        <x-community.field-error field="content" id="community-content-error" />
                        <p class="community-draft-count !mb-0 ml-auto text-[11px]" id="community-draft-count" data-draft-count></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label for="community-category" class="text-xs font-medium text-light-text dark:text-light-text-dark">
                        Categoría
                        <span class="relative mt-1.5 block">
                            <select name="content_category_id" id="community-category" class="!mt-0 !h-9 !w-full appearance-none !rounded-[var(--radius-ui)] !border !border-black/10 !bg-[var(--community-soft)] !px-3 !pr-8 !py-0 text-xs font-medium dark:!border-white/10" @if ($errors->has('content_category_id')) aria-invalid="true" aria-describedby="community-category-error" @endif>
                                <option value="">Sin categoría</option>
                                @foreach ($categories as $category)
                                    @if ($category->is_active || $category->id === ($post['category']['id'] ?? null))
                                        <option value="{{ $category->id }}" @selected((string) old('content_category_id', $post['category']['id'] ?? '') === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (histórica)' }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <x-lucide-chevron-down class="pointer-events-none absolute right-2.5 top-1/2 !size-3.5 -translate-y-1/2 text-light-text dark:text-light-text-dark" aria-hidden="true" />
                        </span>
                        <x-community.field-error field="content_category_id" id="community-category-error" />
                    </label>

                    <label for="community-specialty" class="text-xs font-medium text-light-text dark:text-light-text-dark">
                        Especialidad
                        <span class="relative mt-1.5 block">
                            <select name="legal_specialty_id" id="community-specialty" class="!mt-0 !h-9 !w-full appearance-none !rounded-[var(--radius-ui)] !border !border-black/10 !bg-[var(--community-soft)] !px-3 !pr-8 !py-0 text-xs font-medium dark:!border-white/10" @if ($errors->has('legal_specialty_id')) aria-invalid="true" aria-describedby="community-specialty-error" @endif>
                                <option value="">Sin especialidad</option>
                                @foreach ($specialties as $specialty)
                                    @if ($specialty->is_active || $specialty->id === ($post['specialty']['id'] ?? null))
                                        <option value="{{ $specialty->id }}" @selected((string) old('legal_specialty_id', $post['specialty']['id'] ?? '') === (string) $specialty->id)>{{ $specialty->name }}{{ $specialty->is_active ? '' : ' (histórica)' }}</option>
                                    @endif
                                @endforeach
                            </select>
                            <x-lucide-chevron-down class="pointer-events-none absolute right-2.5 top-1/2 !size-3.5 -translate-y-1/2 text-light-text dark:text-light-text-dark" aria-hidden="true" />
                        </span>
                        <x-community.field-error field="legal_specialty_id" id="community-specialty-error" />
                    </label>
                </div>

                <fieldset class="border-0 p-0" @if ($tagError) aria-invalid="true" aria-describedby="community-tags-error" @endif>
                    <legend class="mb-2 text-xs font-medium text-light-text dark:text-light-text-dark">Etiquetas <span class="opacity-75">(máx. {{ config('community.max_tags') }})</span></legend>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($tags as $tag)
                            @if ($tag->is_active || in_array($tag->id, array_column($post['tags'] ?? [], 'id')))
                                <label class="!inline-flex cursor-pointer items-center">
                                    <input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" class="peer sr-only" @if ($tagError) aria-invalid="true" aria-describedby="community-tags-error" @endif @checked(in_array($tag->id, old('tag_ids', array_column($post['tags'] ?? [], 'id'))))>
                                    <span class="inline-flex min-h-7 items-center rounded-full border border-transparent bg-[var(--community-soft)] px-3 py-1 text-xs font-medium text-light-text transition-colors hover:text-text peer-checked:border-accent/25 peer-checked:bg-accent/10 peer-checked:font-semibold peer-checked:text-accent peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-accent dark:text-light-text-dark dark:hover:text-text-dark dark:peer-checked:border-accent-text-dark/30 dark:peer-checked:bg-selected-dark dark:peer-checked:text-accent-text-dark">#{{ $tag->name }}{{ $tag->is_active ? '' : ' (histórica)' }}</span>
                                </label>
                            @endif
                        @endforeach
                    </div>
                    <x-community.field-error field="tag_ids" id="community-tags-error" />
                </fieldset>

                @if ($post && $post['attachments'])
                    <fieldset class="rounded-[var(--radius-ui)] border border-black/10 p-3 dark:border-white/10" @if ($attachmentError) aria-invalid="true" aria-describedby="community-attachments-error" @endif>
                        <legend class="px-1.5 text-xs font-semibold text-text dark:text-text-dark">Archivos actuales (marca para conservar)</legend>
                        <div class="mt-1 flex flex-wrap gap-2">
                            @foreach ($post['attachments'] as $file)
                                <label class="!flex items-center gap-2 rounded-lg bg-[var(--community-soft)] px-2.5 py-1.5 text-xs">
                                    <input type="checkbox" name="attachment_ids[]" value="{{ $file['id'] }}" @if ($attachmentError) aria-invalid="true" aria-describedby="community-attachments-error" @endif @checked(in_array($file['id'], old('attachment_ids', array_column($post['attachments'], 'id'))))>
                                    <span>{{ $file['name'] }} · {{ strtoupper($file['extension']) }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endif
                <x-community.field-error field="attachment_ids" id="community-attachments-error" />

                @if (! $post || $post['author']['id'] === auth()->id())
                    <div class="relative rounded-xl border border-dashed border-black/15 bg-[var(--community-soft)] p-3.5 transition-colors hover:border-accent/45 hover:bg-accent/5 dark:border-white/15 dark:hover:border-accent-text-dark/45">
                        <label class="!flex cursor-pointer items-center gap-3" for="community-uploads">
                            <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent dark:bg-selected-dark dark:text-accent-text-dark" aria-hidden="true">
                                <x-lucide-paperclip class="!size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <strong class="block text-xs font-semibold text-text dark:text-text-dark">Adjuntar imágenes, video o documentos</strong>
                                <span id="community-upload-hint" class="mt-0.5 block text-[11px] text-light-text dark:text-light-text-dark">Máx. {{ config('community.max_attachments') }} archivos · Img {{ config('attachments.max_bytes.image') / 1048576 }} MiB · Video {{ config('attachments.max_bytes.video') / 1048576 }} MiB · PDF/TXT {{ config('attachments.max_bytes.document') / 1048576 }} MiB</span>
                            </span>
                            <input type="file" id="community-uploads" name="uploads[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf,.txt" data-community-uploads class="!mt-0 absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-describedby="community-upload-hint community-upload-client-error{{ $uploadError ? ' community-uploads-error' : '' }}" @if ($uploadError) aria-invalid="true" @endif>
                        </label>
                    </div>
                    <x-community.field-error field="uploads" :also="['file']" id="community-uploads-error" />
                    <p id="community-upload-client-error" class="community-file-error" data-upload-error role="alert" hidden></p>
                @endif
            </div>

            @if (! $post || $post['author']['id'] === auth()->id())
                <ul class="community-previews !mt-0 !grid !grid-cols-1 gap-2.5 sm:!grid-cols-2 md:sticky md:top-0" data-upload-list aria-label="Archivos seleccionados"></ul>
            @endif
        </div>
    </div>

    <div class="community-create-footer flex flex-wrap items-center justify-between gap-3 border-t border-black/8 !bg-[var(--community-surface)] !px-5 !py-3.5 dark:border-white/10">
        <p class="community-muted !m-0 text-xs" data-submit-status role="status" aria-live="polite"></p>
        <div class="flex items-center gap-2 ml-auto">
            <button class="community-secondary !min-h-9 !rounded-full !px-4 !text-xs" name="intent" value="{{ $post ? 'save' : 'draft' }}">{{ $post ? 'Guardar cambios' : 'Guardar borrador' }}</button>
            @if (! $post || $post['status'] === 'draft')
                <button class="community-primary !min-h-9 !rounded-full !px-5 !text-xs" name="intent" value="publish"><x-lucide-send class="!size-3.5" aria-hidden="true" /><span>Publicar</span></button>
            @endif
        </div>
    </div>
</form>
