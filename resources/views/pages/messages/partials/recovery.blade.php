<section class="messages-recovery" data-messaging-recovery hidden role="status" aria-live="polite" aria-atomic="true" aria-labelledby="messages-recovery-title">
    <h2 id="messages-recovery-title" data-recovery-title>La actualización está en pausa</h2>
    <p data-recovery-message></p>
    <div class="messages-recovery-actions">
        <button type="button" class="messages-action" data-read-retry>Volver a consultar</button>
        <a class="messages-action" href="{{ route('messages') }}" data-recovery-access data-turbo="false" hidden>Revisar mi acceso a Mensajes</a>
        <a class="messages-action" href="{{ route('landing') }}" data-turbo="false">Ir a Inicio</a>
    </div>
</section>
