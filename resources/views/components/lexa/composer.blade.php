@props(['modes'])
<div class="lexa-composer-card">
    <form class="lexa-composer" data-composer aria-label="Preparar una consulta">
        <label class="lexa-sr" for="lexa-prompt">Describe tu situación legal</label>
        <textarea id="lexa-prompt" rows="3" maxlength="5000" data-prompt placeholder="Describe tu situación legal o pregunta lo que necesites…" aria-describedby="lexa-composer-help"></textarea>
        <ul class="lexa-draft-files" data-draft-files aria-label="Contexto adjunto"></ul>
        <p class="lexa-file-error" data-file-error role="alert" hidden></p>
        <div class="lexa-composer-tools">
            <div class="lexa-composer-options">
                <label class="lexa-mode"><span class="lexa-sr">Tipo de consulta</span><select data-consultation-mode aria-label="Tipo de consulta">@foreach ($modes as $mode)<option value="{{ $mode['id'] }}">{{ $mode['label'] }}</option>@endforeach</select><x-lucide-chevron-down aria-hidden="true" /></label>
                <label class="lexa-attach" aria-disabled="true" title="Adjuntos disponibles próximamente"><x-lucide-paperclip aria-hidden="true" /><span>Adjuntos próximamente</span><input type="file" multiple data-attach disabled accept=".jpg,.jpeg,.png,.webp,.pdf,.docx" aria-label="Adjuntos no habilitados todavía" aria-describedby="lexa-file-limits"></label>
            </div>
            <div class="lexa-composer-actions">
                <button type="button" class="lexa-icon" disabled aria-label="Entrada de voz no habilitada" title="Entrada de voz no habilitada"><x-lucide-mic aria-hidden="true" /></button>
                <button type="submit" class="lexa-send" data-send disabled aria-label="Enviar consulta"><x-lucide-arrow-up aria-hidden="true" /></button>
            </div>
        </div>
        <span id="lexa-file-limits" class="lexa-sr">La carga de archivos de contexto se habilitará en una etapa posterior.</span>
        <span id="lexa-composer-help" class="lexa-sr">Enter para enviar. Shift más Enter para un salto de línea.</span>
    </form>
</div>