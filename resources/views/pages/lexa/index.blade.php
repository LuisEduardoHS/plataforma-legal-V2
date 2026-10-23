@extends('layouts.app')
@section('title', 'LEXA | Plataforma Legal')

@section('page-assets')
    @vite(['resources/css/lexa.css'])
@endsection
@section('content')
    @php($lexa = $lexa ?? require resource_path('views/pages/lexa/demo.php'))
    <section class="lexa" data-lexa data-stage="welcome" aria-label="LEXA, asistente de orientación legal">
        <script type="application/json" data-lexa-data>{!! Illuminate\Support\Js::encode($lexa) !!}</script>
        <div class="lexa-layout">
            <x-lexa.sidebar />
            <div class="lexa-workspace">
                <div class="lexa-atmosphere" aria-hidden="true"></div>
                <header class="lexa-toolbar">
                    <button type="button" class="lexa-icon lexa-history-toggle" data-open-history aria-label="Abrir historial de consultas" aria-haspopup="dialog"><x-lucide-panel-left aria-hidden="true" /></button>
                    <span class="lexa-session-title" data-session-title>Nueva consulta</span>
                    <button type="button" class="lexa-icon lexa-new-mobile" data-new-session aria-label="Nueva consulta"><x-lucide-square-pen aria-hidden="true" /></button>
                </header>
                <div class="lexa-content">
                    <div class="lexa-welcome" data-welcome>
                        <h1>¿Qué necesitas entender?</h1>
                        <p>Cuéntame tu situación o haz una<br class="lexa-desktop-break"> pregunta legal con tus palabras.</p>
                    </div>
                    <div class="lexa-thread" data-thread role="region" aria-label="Conversación con LEXA" tabindex="0" hidden></div>
                    <div class="lexa-entry">
                        <x-lexa.composer :modes="$lexa['modes']" />
                        <p class="lexa-disclaimer">La orientación informativa no sustituye la asesoría profesional.</p>
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
        <p class="lexa-sr" role="status" aria-live="polite" aria-atomic="true" data-lexa-status></p>
        <template data-session-template><button type="button" class="lexa-history-item"><x-lucide-message-square aria-hidden="true" /><span></span></button></template>
        <template data-message-template>
            <article class="lexa-message">
                <div class="lexa-message-author"><x-lucide-sparkles data-answer-icon aria-hidden="true" /><strong data-message-author></strong><span data-example-label>Respuesta de ejemplo</span></div>
                <p data-message-text></p><div class="lexa-message-files" data-message-files></div>
            </article>
        </template>
        <template data-file-template><button type="button" class="lexa-file"><x-lucide-file-text data-file-icon aria-hidden="true" /><span><strong data-file-name></strong><small data-file-meta></small></span></button></template>
        <template data-remove-template><button type="button" class="lexa-icon"><x-lucide-x aria-hidden="true" /></button></template>
        <template data-preview-link-template><a class="lexa-preview-link"><x-lucide-external-link aria-hidden="true" /> Abrir archivo local</a></template>
        <noscript><p>Activa JavaScript para escribir y consultar el historial de LEXA.</p></noscript>
    </section>
@endsection
