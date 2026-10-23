@extends('layouts.app')
@section('title', 'Mensajes | Plataforma Legal')

@section('content')
    @vite(['resources/css/messages.css', 'resources/js/messages.js'])
    @php($messaging = $messaging ?? require resource_path('views/pages/messages/demo.php'))
    <section class="messages" data-messaging data-view="list" aria-label="Mensajes">
        <script type="application/json" data-messaging-data>{!! Illuminate\Support\Js::encode($messaging) !!}</script>
        <div class="messages-layout">
            <x-messages.conversations />
            <section class="messages-chat" aria-labelledby="messages-contact-name">
                <header class="messages-chat-header">
                    <button type="button" class="messages-icon messages-back" data-back aria-label="Volver a conversaciones"><x-lucide-arrow-left aria-hidden="true" /></button>
                    <div class="messages-chat-identity">
                        <span class="messages-eyebrow">Chat</span>
                        <div class="messages-contact">
                            <span class="messages-avatar" data-contact-initials aria-hidden="true"></span>
                            <div><h1 id="messages-contact-name"><span data-contact-name>Selecciona una conversación</span><x-lucide-badge-check data-contact-verified aria-label="Profesional verificado" role="img" hidden /></h1><span class="messages-specialty" data-contact-specialty></span></div>
                        </div>
                    </div>
                    <button type="button" class="messages-icon" data-open-info aria-label="Información del contacto" aria-haspopup="dialog"><x-lucide-info aria-hidden="true" /></button>
                </header>
                <div class="messages-chat-body">
                    <div class="messages-history" data-history role="region" aria-label="Historial de mensajes" tabindex="0"></div>
                    <p class="messages-sr" role="status" aria-live="polite" data-message-status></p>
                    <x-messages.composer />
                </div>
            </section>
            <x-messages.information />
        </div>
        <dialog class="messages-info-dialog" data-info-dialog aria-label="Información del contacto"></dialog>
        <dialog class="messages-preview-dialog" data-preview-dialog aria-labelledby="messages-preview-title">
            <div class="messages-panel-heading"><h2 id="messages-preview-title">Archivo compartido</h2><button type="button" class="messages-icon" data-close-preview aria-label="Cerrar vista previa"><x-lucide-x aria-hidden="true" /></button></div>
            <div data-file-preview></div>
        </dialog>
        <template data-conversation-template>
            <button type="button" class="messages-conversation">
                <span class="messages-avatar" data-list-initials aria-hidden="true"></span>
                <span class="messages-conversation-copy"><span class="messages-conversation-title"><strong data-list-name></strong><x-lucide-badge-check data-list-verified aria-label="Profesional verificado" role="img" /></span><span class="messages-last" data-list-last></span></span>
                <span class="messages-conversation-meta"><time data-list-time></time><span class="messages-unread" data-list-unread></span></span>
            </button>
        </template>
        <template data-message-template>
            <article class="messages-bubble"><p data-message-text></p><div class="messages-message-files" data-message-files></div><footer><time data-message-time></time><span data-message-state></span></footer></article>
        </template>
        <template data-file-template>
            <button type="button" class="messages-file"><x-lucide-file-text data-file-icon aria-hidden="true" /><span><strong data-file-name></strong><small data-file-meta></small></span><x-lucide-arrow-up-right aria-hidden="true" /></button>
        </template>
        <template data-remove-template><button type="button" class="messages-icon"><x-lucide-x aria-hidden="true" /></button></template>
        <template data-video-template><x-lucide-video aria-hidden="true" /></template>
        <template data-download-template><a class="messages-file-download"><x-lucide-download aria-hidden="true" /><span>Abrir archivo</span></a></template>
        <template data-skeleton-template><x-messages.skeleton /></template>
        <noscript><p class="messages-notice">Activa JavaScript para consultar las conversaciones y escribir mensajes.</p></noscript>
    </section>
@endsection
