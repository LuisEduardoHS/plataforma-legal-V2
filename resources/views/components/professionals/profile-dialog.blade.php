<dialog class="professional-dialog" data-profile-dialog aria-labelledby="professional-detail-title" aria-describedby="professional-detail-specialty">
    <div class="professional-dialog-cover" aria-hidden="true"><svg viewBox="0 0 600 100" preserveAspectRatio="none"><path d="M0 90C120 120 170 0 300 42S470 100 600 15V100H0Z" /></svg></div>
    <button type="button" class="professional-dialog-close professionals-icon" data-close-profile aria-label="Cerrar perfil"><x-lucide-x aria-hidden="true" /></button>
    <div class="professional-dialog-content">
        <div class="professional-detail-identity"><span class="professional-avatar professional-avatar-large" data-detail-initials aria-hidden="true"></span><div><span class="professional-role" data-detail-role></span><h2 id="professional-detail-title" tabindex="-1"></h2><p id="professional-detail-specialty"></p></div></div>
        <div class="professional-detail-meta"><span><x-lucide-map-pin aria-hidden="true" /><span data-detail-location></span></span><span><x-lucide-badge-check aria-hidden="true" /><span data-detail-verification></span></span></div>
        <section class="professional-detail-section"><h3>Sobre el perfil</h3><p data-detail-bio></p></section>
        <section class="professional-detail-section"><h3>Áreas de atención</h3><ul class="professional-detail-topics" data-detail-topics></ul></section>
        <div class="professional-detail-facts"><div><h3>Modalidad</h3><p data-detail-modalities></p></div><div><h3>Idiomas</h3><p data-detail-languages></p></div></div>
        <p class="professional-example" data-detail-example hidden>Perfil de ejemplo. Los datos y las insignias de esta vista no acreditan a un profesional real.</p>
        <p class="professionals-error" data-detail-error role="alert" hidden></p>
        <div class="professional-detail-actions"><a class="professionals-primary" data-prepare-consultation>Preparar consulta <x-lucide-arrow-up-right aria-hidden="true" /></a><a class="professionals-secondary" data-detail-conversation hidden>Ver conversación <x-lucide-message-circle aria-hidden="true" /></a><button type="button" class="professionals-secondary" data-copy-profile><x-lucide-link aria-hidden="true" /><span>Copiar enlace</span></button></div>
        <p class="professional-copy-status" data-copy-status role="status" aria-live="polite"></p>
    </div>
</dialog>
