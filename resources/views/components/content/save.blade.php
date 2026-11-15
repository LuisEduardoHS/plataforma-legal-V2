@props(['type', 'id', 'saved' => false, 'canSave' => true])
@if ($canSave || $saved)
    @auth
        <div class="content-save-control">
            <form method="POST" action="{{ route($saved ? 'saved.destroy' : 'saved.store', ['type' => $type, 'target' => $id]) }}" data-save-form data-save-type="{{ $type }}" data-save-id="{{ $id }}">
                @csrf @method($saved ? 'DELETE' : 'PUT')
                <button class="resource-save {{ $saved ? 'is-saved' : '' }}" aria-pressed="{{ $saved ? 'true' : 'false' }}" aria-label="{{ $saved ? 'Quitar de Guardados' : 'Guardar contenido' }}" title="{{ $saved ? 'Quitar de Guardados' : 'Guardar contenido' }}"><x-lucide-bookmark aria-hidden="true" /></button>
            </form>
            <p class="content-save-message" data-save-message role="status" aria-live="polite" hidden></p>
        </div>
    @else
        <a class="resource-save" href="{{ route('login') }}" aria-label="Inicia sesión para guardar"><x-lucide-bookmark aria-hidden="true" /></a>
    @endauth
@endif
