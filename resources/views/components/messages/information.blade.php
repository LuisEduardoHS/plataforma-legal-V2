<aside class="messages-info" data-information aria-labelledby="messages-info-title">
    <div class="messages-panel-heading"><h2 id="messages-info-title" class="messages-eyebrow">Información</h2><button type="button" class="messages-icon messages-info-close" data-close-info aria-label="Cerrar información"><x-lucide-x aria-hidden="true" /></button></div>
    <div class="messages-profile">
        <span class="messages-avatar messages-avatar-large" data-contact-initials aria-hidden="true"></span>
        <h3><span data-contact-name></span><x-lucide-badge-check data-contact-verified aria-label="Profesional verificado" role="img" hidden /></h3>
        <span class="messages-specialty" data-contact-specialty></span>
        <button type="button" class="messages-profile-link" data-profile disabled title="El perfil de este contacto todavía no está conectado">Ver perfil <x-lucide-arrow-right aria-hidden="true" /></button>
    </div>
    <section class="messages-shared" aria-labelledby="messages-shared-title">
        <h3 id="messages-shared-title">Archivos compartidos</h3>
        <div data-shared-files></div>
        <p class="messages-muted" data-shared-empty hidden>Aún no hay archivos en esta conversación.</p>
        <button type="button" class="messages-profile-link" data-all-files hidden aria-expanded="false">Ver todos <x-lucide-arrow-right aria-hidden="true" /></button>
    </section>
</aside>
