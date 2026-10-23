<div class="messages-composer-wrap">
    <p class="messages-file-error" data-file-error role="alert" hidden></p>
    <ul class="messages-draft-files" data-draft-files aria-label="Adjuntos del mensaje"></ul>
    <form class="messages-composer" data-composer aria-label="Escribir mensaje">
        <label class="messages-attach messages-icon">
            <x-lucide-plus aria-hidden="true" />
            <input type="file" multiple accept=".jpg,.jpeg,.png,.webp,.pdf,.docx,.mp4,.webm" data-attach aria-label="Adjuntar archivos" aria-describedby="messages-attachment-help">
        </label>
        <label for="messages-draft" class="messages-sr">Escribe un mensaje</label>
        <textarea id="messages-draft" rows="1" maxlength="5000" placeholder="Escribe un mensaje…" data-draft aria-describedby="messages-local-help"></textarea>
        <button type="submit" class="messages-send" disabled data-send aria-label="Añadir mensaje a esta conversación local"><x-lucide-arrow-up aria-hidden="true" /></button>
    </form>
    <p class="messages-local-help" id="messages-local-help">Solo en esta página. Los mensajes no se entregan a otras personas.</p>
    <span class="messages-sr" id="messages-attachment-help">Máximo 4 archivos y 80 MB en total. Imágenes hasta 10 MB, documentos 20 MB y videos 50 MB.</span>
</div>
