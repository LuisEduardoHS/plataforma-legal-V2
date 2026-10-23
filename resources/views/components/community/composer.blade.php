<section class="community-composer community-surface" aria-label="Nueva publicación">
    <div class="community-composer-input">
        <x-community.avatar initials="TU" />
        <button type="button" class="community-compose-prompt" data-open-create aria-haspopup="dialog">
            ¿Qué quieres compartir con la comunidad?
        </button>
    </div>
    <div class="community-composer-tools">
        <div class="community-attachments">
            @foreach (['image' => ['image', 'Imagen'], 'video' => ['video', 'Video'], 'document' => ['file-text', 'Documento']] as $kind => [$icon, $label])
                <button type="button" data-open-create="{{ $kind }}" aria-haspopup="dialog" aria-label="Añadir {{ mb_strtolower($label) }}">
                    <x-dynamic-component :component="'lucide-' . $icon" aria-hidden="true" /><span>{{ $label }}</span>
                </button>
            @endforeach
        </div>
        <button type="button" class="community-primary community-compose-button" data-open-create aria-haspopup="dialog" aria-label="Crear publicación">
            Publicar
        </button>
    </div>
</section>
