@extends('pages.articles.layout')
@section('library-title', $item ? 'Editar contenido' : 'Crear borrador')
@section('library-errors')
    <x-ui.error-summary :fields="array_merge(['title' => 'editorial-title', 'content_category_id' => 'editorial-category', 'tag_ids' => 'editorial-tag_ids', 'specialty_ids' => 'editorial-specialty_ids', 'attachment_ids' => 'editorial-attachments'], $kind->value === 'article' ? ['summary' => 'editorial-summary', 'body' => 'editorial-body'] : ['description' => 'editorial-description', 'resource_type' => 'editorial-type', 'external_url' => 'editorial-url'], (!$item || $item['author']['id'] === auth()->id()) ? ['uploads' => 'editorial-uploads'] : [])" />
@endsection
@section('library-content')
<form method="POST" action="{{ $item ? route($kind->permission().'.update', $item['id']) : route($kind->permission().'.store') }}" enctype="multipart/form-data" class="content-editor" data-editorial-form>
    @csrf
    @if ($item) @method('PUT')<input type="hidden" name="version" value="{{ old('version', $item['version']) }}">@endif
    <x-ui.input name="title" id="editorial-title" label="Título" :value="$item['title'] ?? ''" maxlength="200" required />
    @if ($kind->value === 'article')
        <x-ui.input name="summary" id="editorial-summary" label="Resumen" :value="$item['summary'] ?? ''" multiline maxlength="1000" rows="3" />
        <x-ui.input name="body" id="editorial-body" label="Contenido del artículo" :value="$item['body'] ?? ''" multiline maxlength="100000" rows="16" />
    @else
        <x-ui.input name="description" id="editorial-description" label="Descripción" :value="$item['description'] ?? ''" multiline maxlength="5000" rows="9" />
        <label for="editorial-type">Tipo de recurso<select id="editorial-type" name="resource_type" required aria-invalid="{{ $errors->has('resource_type') ? 'true' : 'false' }}" @if ($errors->has('resource_type')) aria-describedby="editorial-type-error" @endif>@foreach (App\Enums\LegalResourceType::cases() as $type)<option value="{{ $type->value }}" @selected(old('resource_type', $item['resource_type'] ?? 'guide') === $type->value)>{{ __('content.resource_types.'.$type->value, [], 'es') }}</option>@endforeach</select></label>
        <x-ui.field-error field="resource_type" id="editorial-type-error" />
        <x-ui.input name="external_url" id="editorial-url" label="Enlace externo HTTPS" type="url" :value="$item['external_url'] ?? ''" maxlength="2048" placeholder="https://" />
        <p class="content-muted">Documentos y plantillas requieren archivo. Los enlaces institucionales requieren un dominio admitido; esto no certifica su contenido.</p>
    @endif
    <p class="content-muted">El texto se muestra como texto plano. No se interpreta HTML.</p>
    <label for="editorial-category">Categoría<select id="editorial-category" name="content_category_id" aria-invalid="{{ $errors->has('content_category_id') ? 'true' : 'false' }}" @if ($errors->has('content_category_id')) aria-describedby="editorial-category-error" @endif><option value="">Sin categoría</option>@foreach ($categories as $category)<option value="{{ $category['id'] }}" @selected(old('content_category_id', $item['category']['id'] ?? '') == $category['id'])>{{ $category['name'] }}{{ $category['is_active'] ? '' : ' (inactiva, ya asociada)' }}</option>@endforeach</select></label>
    <x-ui.field-error field="content_category_id" id="editorial-category-error" />
    @foreach (['tags' => ['tag_ids', 'Etiquetas'], 'specialties' => ['specialty_ids', 'Especialidades']] as $group => [$field, $label])
        <fieldset id="editorial-{{ $field }}" tabindex="-1" aria-invalid="{{ ($errors->has($field) || $errors->has($field.'.*')) ? 'true' : 'false' }}" @if ($errors->has($field) || $errors->has($field.'.*')) aria-describedby="editorial-{{ $field }}-error" @endif><legend>{{ $label }}</legend><div class="content-check-grid">@forelse ($$group as $option)<label class="content-checkbox"><input type="checkbox" name="{{ $field }}[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], old($field, array_column($item[$group] ?? [], 'id'))))> {{ $option['name'] }}{{ $option['is_active'] ? '' : ' (inactiva, ya asociada)' }}</label>@empty<p class="content-muted">No hay opciones activas disponibles.</p>@endforelse</div><x-ui.field-error :field="$field" :id="'editorial-'.$field.'-error'" /></fieldset>
    @endforeach
    <fieldset id="editorial-attachments" tabindex="-1" aria-invalid="{{ ($errors->has('attachment_ids') || $errors->has('attachment_ids.*')) ? 'true' : 'false' }}" @if ($errors->has('attachment_ids') || $errors->has('attachment_ids.*')) aria-describedby="editorial-attachments-error" @endif><legend>Archivos actuales</legend>@forelse ($item['attachments'] ?? [] as $file)<label class="content-checkbox"><input type="checkbox" name="attachment_ids[]" value="{{ $file['id'] }}" @checked(in_array($file['id'], old('attachment_ids', array_column($item['attachments'], 'id'))))> Conservar {{ $file['name'] }} ({{ strtoupper($file['extension']) }})</label>@empty<p class="content-muted">Sin archivos asociados.</p>@endforelse<p class="content-muted">Retirar un archivo del contenido actual no elimina su copia histórica.</p><x-ui.field-error field="attachment_ids" id="editorial-attachments-error" /></fieldset>
    @if (!$item || $item['author']['id'] === auth()->id())<label for="editorial-uploads">Agregar archivos<input id="editorial-uploads" aria-invalid="{{ ($errors->has('uploads') || $errors->has('uploads.*')) ? 'true' : 'false' }}" aria-describedby="editorial-upload-help{{ ($errors->has('uploads') || $errors->has('uploads.*')) ? ' editorial-uploads-error' : '' }}" type="file" name="uploads[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf,.txt,.mp3" data-editorial-files></label><x-ui.field-error field="uploads" id="editorial-uploads-error" />@else<p class="content-muted">Solo el autor puede agregar archivos propios. Puedes conservar o retirar las asociaciones actuales.</p>@endif
    <p id="editorial-upload-help" class="content-muted">Hasta cuatro archivos en total. Máximos del catálogo: imágenes, 10 MiB; video, 100 MiB; PDF, TXT y MP3, 20 MiB. El servidor puede limitar el tamaño total del envío. Solo descargas; sin vista previa. Tras un error debes seleccionar los archivos nuevos otra vez.</p>
    <p class="content-error" data-editorial-file-error role="alert" hidden></p>
    <script type="application/json" data-editorial-file-rules>@json(['maxCount' => config('content-library.max_attachments'), 'maxBytes' => config('attachments.max_bytes'), 'types' => config('attachments.types')])</script>
    <div class="content-actions"><button class="content-primary" name="intent" value="save">{{ $item ? 'Guardar cambios' : 'Guardar borrador' }}</button>
        @if ((!$item && ((auth()->user()->can($kind->permission().'.publish.own') && auth()->user()->can($kind->permission().'.update.own')) || auth()->user()->can($kind->permission().'.manage'))) || ($item && in_array('published', $item['transitions'], true)))<button class="content-secondary" name="intent" value="publish">Guardar y publicar</button>@endif
        <a class="content-secondary" href="{{ $item ? route($kind->permission().'.show', $item['id']) : route($kind->permission().'.workspace') }}">Cancelar</a>
    </div>
</form>
@endsection
