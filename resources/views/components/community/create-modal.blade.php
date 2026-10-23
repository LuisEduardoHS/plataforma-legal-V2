@props(['categories'])
<dialog class="community-dialog community-create" data-create-dialog aria-labelledby="community-create-title">
    <div class="community-dialog-heading">
        <h2 id="community-create-title">Crear publicación</h2>
        <button type="button" class="community-icon-button" data-close-create aria-label="Cerrar"><x-lucide-x aria-hidden="true" /></button>
    </div>
    <div class="community-create-content">
        <div class="community-create-author">
            <x-community.avatar initials="TU" />
            <div><strong>María Hernández</strong><span class="community-muted">Perfil de ejemplo</span></div>
        </div>
        <label class="community-field-label" for="community-category">Categoría jurídica</label>
        <div class="community-category">
            <x-lucide-tag aria-hidden="true" />
            <select id="community-category">
                <option value="">Selecciona una categoría</option>
                @foreach ($categories as $category)<option>{{ $category['name'] }}</option>@endforeach
            </select>
            <x-lucide-chevron-down aria-hidden="true" />
        </div>
        <label class="community-sr-only" for="community-draft">Contenido de la publicación</label>
        <textarea id="community-draft" rows="5" maxlength="5000" placeholder="Comparte una pregunta o inicia una conversación…" aria-describedby="community-draft-count"></textarea>
        <div class="community-draft-count" id="community-draft-count">0 / 5000</div>
        <fieldset class="community-upload">
            <legend>Añadir a tu publicación</legend>
            <div class="community-upload-options">
                @foreach (['image' => ['image', 'Imágenes', '.jpg,.jpeg,.png,.webp'], 'video' => ['video', 'Videos', '.mp4,.webm'], 'document' => ['file-text', 'Documentos', '.pdf,.docx']] as $kind => [$icon, $label, $accept])
                    <label class="community-upload-option" data-upload-kind="{{ $kind }}">
                        <x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" />
                        <span>{{ $label }}</span>
                        <input type="file" data-file-kind="{{ $kind }}" accept="{{ $accept }}" multiple aria-label="Añadir {{ mb_strtolower($label) }}" aria-describedby="community-upload-help">
                    </label>
                @endforeach
            </div>
            <p class="community-muted" id="community-upload-help">Hasta 4 archivos · Imágenes 10 MB · Videos 50 MB · Documentos 20 MB · Total 80 MB.</p>
        </fieldset>
        <p class="community-file-error" data-file-error role="alert" hidden></p>
        <ul class="community-previews" data-previews aria-label="Archivos adjuntos"></ul>
        <p class="community-sr-only" data-attachment-status role="status" aria-live="polite"></p>
    </div>
    <div class="community-create-footer">
        <p class="community-muted" id="community-publish-help">Publicación aún no habilitada. Tu borrador y archivos permanecen solo en esta ventana.</p>
        <button type="button" class="community-primary" disabled aria-describedby="community-publish-help"><x-lucide-send aria-hidden="true" /> Publicar</button>
    </div>
    <div class="community-discard" data-discard hidden role="group" aria-labelledby="community-discard-title">
        <h3 id="community-discard-title">¿Descartar el borrador?</h3>
        <p>Se eliminarán el texto y los archivos que seleccionaste.</p>
        <div><button type="button" class="community-secondary" data-keep-draft>Seguir editando</button><button type="button" class="community-primary" data-discard-draft>Descartar</button></div>
    </div>
</dialog>
