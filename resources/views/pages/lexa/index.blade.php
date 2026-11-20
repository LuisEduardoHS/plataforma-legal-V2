@extends('layouts.app')
@section('title', 'LEXA | Plataforma Legal')

@section('page-assets')
    @vite(['resources/css/lexa.css'])
@endsection
@section('content')
    @php($lexa = $lexa ?? ['current_user' => ['id' => auth()->id(), 'name' => 'Tú'], 'generation_enabled' => false, 'modes' => [['id' => 'orientation', 'label' => 'Orientación'], ['id' => 'contract', 'label' => 'Revisar un contrato'], ['id' => 'organize', 'label' => 'Ordenar mi consulta']], 'suggestions' => [], 'sessions' => [], 'next_cursor' => null, 'endpoints' => ['conversations' => route('lexa.conversations.index'), 'login' => route('login')]])
    <section class="lexa" data-lexa data-stage="welcome" aria-label="LEXA, asistente de orientación legal">
        <script type="application/json" data-lexa-data>{!! Illuminate\Support\Js::encode($lexa) !!}</script>
        <div class="lexa-layout">
            <x-lexa.sidebar />
            <div class="lexa-workspace">
                <div class="lexa-atmosphere" aria-hidden="true"></div>
                <header class="lexa-toolbar">
                    <button type="button" class="lexa-icon lexa-history-toggle" data-open-history aria-label="Abrir historial de consultas" aria-haspopup="dialog"><x-lucide-panel-left aria-hidden="true" /></button>
                    <span class="lexa-session-title" data-session-title>Nueva consulta</span>
                    <button type="button" class="lexa-icon" data-delete-session hidden aria-label="Eliminar conversación" title="Eliminar conversación"><x-lucide-trash-2 aria-hidden="true" /></button>
                    <button type="button" class="lexa-icon lexa-new-mobile" data-new-session aria-label="Nueva consulta"><x-lucide-square-pen aria-hidden="true" /></button>
                </header>
                <div class="lexa-content">
                    <div class="lexa-welcome" data-welcome>
                        <h1>¿Qué necesitas entender?</h1>
                        <p>Cuéntame tu situación o haz una<br class="lexa-desktop-break"> pregunta legal con tus palabras.</p>
                    </div>
                    <div class="lexa-thread" data-thread role="region" aria-label="Conversación con LEXA" tabindex="0" hidden>
                        <div class="lexa-thread-list" data-thread-list></div>
                        <button type="button" class="lexa-load-more" data-load-more-messages hidden>Cargar más mensajes</button>
                    </div>
                    <div class="lexa-entry">
                        <div class="lexa-banner" data-lexa-banner role="status" aria-live="polite" hidden>
                            <p data-lexa-banner-text></p>
                            <div class="lexa-banner-actions">
                                <button type="button" class="lexa-banner-btn" data-lexa-banner-retry hidden>Reintentar</button>
                                <button type="button" class="lexa-banner-btn" data-lexa-banner-reload hidden>Actualizar</button>
                                <a class="lexa-banner-btn" href="{{ $lexa['endpoints']['login'] ?? route('login') }}" data-lexa-banner-login hidden data-turbo="false">Iniciar sesión</a>
                            </div>
                        </div>
                        <x-lexa.composer :modes="$lexa['modes']" />
                        <p class="lexa-disclaimer" data-lexa-disclaimer>
                            @if ($lexa['generation_enabled'] ?? false)
                                Orientación informativa automatizada. Todavía no consulta fuentes jurídicas oficiales verificadas ni sustituye la asesoría profesional.
                            @else
                                Tus consultas se guardan en tu historial privado. La generación automática con IA está desactivada en esta configuración.
                            @endif
                        </p>
                        <div class="lexa-suggestions" data-suggestions>
                            <p>También puedes comenzar con:</p>
                            <div>
                                @foreach ($lexa['suggestions'] as $suggestion)
                                    <button type="button" data-suggestion="{{ $suggestion['prompt'] }}" data-mode="{{ $suggestion['mode'] }}">{{ $suggestion['label'] }}</button>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <x-lexa.context />
        </div>
        <dialog class="lexa-history-dialog" data-history-dialog aria-label="Historial de consultas"></dialog>
        <dialog class="lexa-preview-dialog" data-preview-dialog aria-labelledby="lexa-preview-title">
            <header><h2 id="lexa-preview-title">Archivo de contexto</h2><button type="button" class="lexa-icon" data-close-preview aria-label="Cerrar vista previa"><x-lucide-x aria-hidden="true" /></button></header>
            <div data-preview-content></div>
        </dialog>
        <dialog class="lexa-preview-dialog" data-delete-dialog aria-labelledby="lexa-delete-title">
            <header><h2 id="lexa-delete-title">Eliminar conversación</h2><button type="button" class="lexa-icon" data-cancel-delete aria-label="Cancelar"><x-lucide-x aria-hidden="true" /></button></header>
            <p class="lexa-delete-copy">Esta conversación dejará de aparecer en tu historial privado.</p>
            <div class="lexa-delete-actions">
                <button type="button" class="lexa-banner-btn" data-cancel-delete>Cancelar</button>
                <button type="button" class="lexa-banner-btn is-danger" data-confirm-delete>Eliminar</button>
            </div>
        </dialog>
        <p class="lexa-sr" role="status" aria-live="polite" aria-atomic="true" data-lexa-status></p>
        <template data-session-template><button type="button" class="lexa-history-item"><x-lucide-message-square aria-hidden="true" /><span></span></button></template>
        <template data-message-template>
            <article class="lexa-message">
                <div class="lexa-message-author">
                    <x-lucide-sparkles data-answer-icon aria-hidden="true" />
                    <strong data-message-author></strong>
                    <button type="button" class="lexa-copy-btn" data-copy-message aria-label="Copiar respuesta" title="Copiar respuesta" hidden><x-lucide-copy aria-hidden="true" /></button>
                </div>
                <div class="lexa-markdown" data-message-text></div><div class="lexa-message-files" data-message-files></div>
            </article>
        </template>
        <template data-file-template><button type="button" class="lexa-file"><x-lucide-file-text data-file-icon aria-hidden="true" /><span><strong data-file-name></strong><small data-file-meta></small></span></button></template>
        <template data-remove-template><button type="button" class="lexa-icon"><x-lucide-x aria-hidden="true" /></button></template>
        <template data-preview-link-template><a class="lexa-preview-link"><x-lucide-external-link aria-hidden="true" /> Abrir archivo local</a></template>
        <noscript><p>Activa JavaScript para escribir y consultar el historial de LEXA.</p></noscript>
    </section>
@endsection
