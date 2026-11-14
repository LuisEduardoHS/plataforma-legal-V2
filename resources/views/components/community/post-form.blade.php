@props(['post' => null, 'categories', 'specialties', 'tags'])
@php
    $types = array_filter(config('attachments.types'), fn ($type) => in_array($type['category'], ['image', 'video', 'document'], true));
    $uploadRules = ['types' => $types, 'maxBytes' => config('attachments.max_bytes'), 'maxCount' => config('community.max_attachments')];
@endphp
<form method="POST" action="{{ $post ? route('community.posts.update', $post['id']) : route('community.posts.store') }}" enctype="multipart/form-data" data-post-form data-upload-rules="{{ Illuminate\Support\Js::encode($uploadRules) }}">
    @csrf @if ($post) @method('PUT')<input type="hidden" name="version" value="{{ old('version', $post['version']) }}">@endif
    <input type="hidden" name="replace_tags" value="1"><input type="hidden" name="replace_attachments" value="1">
    <div class="community-create-content">
        <p class="community-muted">{{ $post ? 'Edita el contenido autorizado. La versión se comprobará al guardar.' : 'Comparte una pregunta o inicia una conversación. Puedes guardar un borrador antes de publicar.' }}</p>
        <label class="community-field-label" for="community-draft">Contenido de la publicación</label>
        <textarea id="community-draft" name="content" rows="6" maxlength="{{ config('community.post_max_length') }}" placeholder="Comparte una pregunta o inicia una conversación…" aria-describedby="community-draft-count">{{ old('content', $post['content'] ?? '') }}</textarea>
        <p class="community-draft-count" id="community-draft-count" data-draft-count></p>
        <div class="community-fields-grid">
            <label for="community-category">Categoría<select name="content_category_id" id="community-category"><option value="">Sin categoría</option>@foreach ($categories as $category)@if ($category->is_active || $category->id === ($post['category']['id'] ?? null))<option value="{{ $category->id }}" @selected((string) old('content_category_id', $post['category']['id'] ?? '') === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (histórica)' }}</option>@endif @endforeach</select></label>
            <label for="community-specialty">Especialidad<select name="legal_specialty_id" id="community-specialty"><option value="">Sin especialidad</option>@foreach ($specialties as $specialty)@if ($specialty->is_active || $specialty->id === ($post['specialty']['id'] ?? null))<option value="{{ $specialty->id }}" @selected((string) old('legal_specialty_id', $post['specialty']['id'] ?? '') === (string) $specialty->id)>{{ $specialty->name }}{{ $specialty->is_active ? '' : ' (histórica)' }}</option>@endif @endforeach</select></label>
        </div>
        <fieldset class="community-checks"><legend>Etiquetas (hasta {{ config('community.max_tags') }})</legend>@foreach ($tags as $tag)@if ($tag->is_active || in_array($tag->id, array_column($post['tags'] ?? [], 'id')))<label><input type="checkbox" name="tag_ids[]" value="{{ $tag->id }}" @checked(in_array($tag->id, old('tag_ids', array_column($post['tags'] ?? [], 'id'))))>{{ $tag->name }}{{ $tag->is_active ? '' : ' (histórica)' }}</label>@endif @endforeach</fieldset>
        <label for="community-visibility">Visibilidad<select name="visibility" id="community-visibility" required><option value="private" @selected(old('visibility', $post['visibility'] ?? 'private') === 'private')>Privada: autor y moderación autorizada</option><option value="public" @selected(old('visibility', $post['visibility'] ?? 'private') === 'public')>Pública</option></select></label>
        @if ($post && $post['attachments'])<fieldset class="community-checks"><legend>Archivos actuales: marca los que quieres conservar</legend>@foreach ($post['attachments'] as $file)<label><input type="checkbox" name="attachment_ids[]" value="{{ $file['id'] }}" @checked(in_array($file['id'], old('attachment_ids', array_column($post['attachments'], 'id'))))>{{ $file['name'] }} · {{ strtoupper($file['extension']) }}</label>@endforeach</fieldset>@endif
        @if (! $post || $post['author']['id'] === auth()->id())
            <fieldset class="community-upload"><legend>Añadir archivos privados</legend><label class="community-field-label" for="community-uploads">Fotografías, video o documentos</label><input type="file" id="community-uploads" name="uploads[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf,.txt" data-community-uploads>
                <p class="community-muted">Hasta {{ config('community.max_attachments') }} archivos en total · Imágenes {{ config('attachments.max_bytes.image') / 1048576 }} MiB · Videos {{ config('attachments.max_bytes.video') / 1048576 }} MiB · PDF/TXT {{ config('attachments.max_bytes.document') / 1048576 }} MiB. Los límites del servidor también se aplican.</p>
            </fieldset><p class="community-file-error" data-upload-error role="alert" hidden></p><ul class="community-previews" data-upload-list aria-label="Archivos seleccionados"></ul>
        @endif
        <p class="community-muted">Los archivos se compartirán mediante descarga autorizada según la visibilidad de la publicación, incluyendo sus nombres. No hay preview público de imágenes o videos. Desasociar un archivo no lo borra físicamente.</p>
    </div>
    <div class="community-create-footer"><div class="community-form-actions"><button class="community-secondary" name="intent" value="{{ $post ? 'save' : 'draft' }}">{{ $post ? 'Guardar cambios' : 'Guardar borrador' }}</button>@if (! $post || $post['status'] === 'draft')<button class="community-primary" name="intent" value="publish"><x-lucide-send aria-hidden="true" />Publicar</button>@endif</div><p class="community-muted" data-submit-status role="status" aria-live="polite"></p></div>
</form>
