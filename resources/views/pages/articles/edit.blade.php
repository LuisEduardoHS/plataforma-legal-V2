@extends('pages.articles.layout')
@section('library-title', $item ? 'Editar contenido' : 'Crear borrador')
@section('library-content')
<form method="POST" action="{{ $item ? route($kind->permission().'.update', $item['id']) : route($kind->permission().'.store') }}" enctype="multipart/form-data" class="content-editor" data-editorial-form>
    @csrf
    @if ($item) @method('PUT')<input type="hidden" name="version" value="{{ old('version', $item['version']) }}">@endif
    <label>Título<input name="title" value="{{ old('title', $item['title'] ?? '') }}" maxlength="200" required></label>
    @if ($kind->value === 'article')
        <label>Resumen<textarea name="summary" maxlength="1000" rows="3">{{ old('summary', $item['summary'] ?? '') }}</textarea></label>
        <label>Contenido del artículo<textarea name="body" maxlength="100000" rows="16">{{ old('body', $item['body'] ?? '') }}</textarea></label>
    @else
        <label>Descripción<textarea name="description" maxlength="5000" rows="9">{{ old('description', $item['description'] ?? '') }}</textarea></label>
        <label>Tipo de recurso<select name="resource_type" required>@foreach (App\Enums\LegalResourceType::cases() as $type)<option value="{{ $type->value }}" @selected(old('resource_type', $item['resource_type'] ?? 'guide') === $type->value)>{{ __('content.resource_types.'.$type->value, [], 'es') }}</option>@endforeach</select></label>
        <label>Enlace externo HTTPS<input type="url" name="external_url" value="{{ old('external_url', $item['external_url'] ?? '') }}" maxlength="2048" placeholder="https://"></label>
        <p class="content-muted">Documentos y plantillas requieren archivo. Los enlaces institucionales requieren un dominio admitido; esto no certifica su contenido.</p>
    @endif
    <p class="content-muted">El texto se muestra como texto plano. No se interpreta HTML.</p>
    <label>Categoría<select name="content_category_id"><option value="">Sin categoría</option>@foreach ($categories as $category)<option value="{{ $category['id'] }}" @selected(old('content_category_id', $item['category']['id'] ?? '') == $category['id'])>{{ $category['name'] }}{{ $category['is_active'] ? '' : ' (inactiva, ya asociada)' }}</option>@endforeach</select></label>
    @foreach (['tags' => ['tag_ids', 'Etiquetas'], 'specialties' => ['specialty_ids', 'Especialidades']] as $group => [$field, $label])
        <fieldset><legend>{{ $label }}</legend><div class="content-check-grid">@forelse ($$group as $option)<label class="content-checkbox"><input type="checkbox" name="{{ $field }}[]" value="{{ $option['id'] }}" @checked(in_array($option['id'], old($field, array_column($item[$group] ?? [], 'id'))))> {{ $option['name'] }}{{ $option['is_active'] ? '' : ' (inactiva, ya asociada)' }}</label>@empty<p class="content-muted">No hay opciones activas disponibles.</p>@endforelse</div></fieldset>
    @endforeach
    <fieldset><legend>Archivos actuales</legend>@forelse ($item['attachments'] ?? [] as $file)<label class="content-checkbox"><input type="checkbox" name="attachment_ids[]" value="{{ $file['id'] }}" @checked(in_array($file['id'], old('attachment_ids', array_column($item['attachments'], 'id'))))> Conservar {{ $file['name'] }} ({{ strtoupper($file['extension']) }})</label>@empty<p class="content-muted">Sin archivos asociados.</p>@endforelse<p class="content-muted">Retirar un archivo del contenido actual no elimina su copia histórica.</p></fieldset>
    @if (!$item || $item['author']['id'] === auth()->id())<label>Agregar archivos<input type="file" name="uploads[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf,.txt,.mp3" data-editorial-files></label>@else<p class="content-muted">Solo el autor puede agregar archivos propios. Puedes conservar o retirar las asociaciones actuales.</p>@endif
    <p class="content-muted">Hasta cuatro archivos en total. Máximos del catálogo: imágenes, 10 MiB; video, 100 MiB; PDF, TXT y MP3, 20 MiB. El servidor puede limitar el tamaño total del envío. Solo descargas; sin vista previa. Tras un error debes seleccionar los archivos nuevos otra vez.</p>
    <p class="content-error" data-editorial-file-error role="alert" hidden></p>
    <script type="application/json" data-editorial-file-rules>@json(['maxCount' => config('content-library.max_attachments'), 'maxBytes' => config('attachments.max_bytes'), 'types' => config('attachments.types')])</script>
    <div class="content-actions"><button class="content-primary" name="intent" value="save">{{ $item ? 'Guardar cambios' : 'Guardar borrador' }}</button>
        @if ((!$item && ((auth()->user()->can($kind->permission().'.publish.own') && auth()->user()->can($kind->permission().'.update.own')) || auth()->user()->can($kind->permission().'.manage'))) || ($item && in_array('published', $item['transitions'], true)))<button class="content-secondary" name="intent" value="publish">Guardar y publicar</button>@endif
        <a class="content-secondary" href="{{ $item ? route($kind->permission().'.show', $item['id']) : route($kind->permission().'.workspace') }}">Cancelar</a>
    </div>
</form>
@endsection
