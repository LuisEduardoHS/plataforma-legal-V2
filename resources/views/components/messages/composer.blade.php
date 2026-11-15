@props(['context','composer'])
<div class="messages-composer-wrap">
    <p class="messages-notice" data-send-status role="status" aria-live="polite">@if (!$context['can_send'])El envío y la edición no están disponibles por bloqueo o estado de cuenta. El historial accesible se conserva.@endif</p>
    <div data-composer-attachments>
        @foreach ($composer['attachments'] as $file)<div class="messages-prepared-file" data-prepared-id="{{ $file['id'] }}"><span>{{ $file['name'] }} · {{ strtoupper($file['extension']) }}</span><form method="POST" action="{{ route('messages.upload.remove', ['conversation'=>$context['id'],'attachment'=>$file['id']]) }}">@csrf @method('DELETE')<button class="messages-action">Retirar del borrador</button></form></div>@endforeach
    </div>
    <details class="messages-upload"><summary><x-lucide-plus aria-hidden="true" />Adjuntar archivos</summary><form method="POST" action="{{ route('messages.upload', $context['id']) }}" enctype="multipart/form-data" data-upload-form>@csrf<label>Archivos<input type="file" name="uploads[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.mp4,.webm,.pdf,.txt,.mp3" data-attach @disabled(!$context['can_send']) required></label><button class="messages-action" @disabled(!$context['can_send'])>Adjuntar</button><p class="messages-muted">Hasta cuatro archivos. Imágenes: 10 MiB; video: 100 MiB; PDF, TXT y MP3: 20 MiB. El servidor puede limitar el total del envío. Sin vista previa.</p><p data-file-error role="alert" hidden></p></form></details>
    <form method="POST" action="{{ route('messages.send', $context['id']) }}" class="messages-composer" data-composer aria-label="Escribir mensaje">
        @csrf<input type="hidden" name="client_token" value="{{ old('client_token', $composer['client_token']) }}">
        <div class="messages-composer-input"><div data-send-attachments>@foreach ($composer['attachments'] as $file)<label class="messages-file-choice"><input type="checkbox" name="attachment_ids[]" value="{{ $file['id'] }}" @checked(in_array($file['id'], old('attachment_ids', array_column($composer['attachments'],'id'))))> Enviar {{ $file['name'] }}</label>@endforeach</div><label for="messages-draft" class="messages-sr">Escribe un mensaje</label><textarea id="messages-draft" name="content" rows="1" maxlength="10000" placeholder="Escribe un mensaje…" data-draft @disabled(!$context['can_send'])>{{ old('content') }}</textarea></div>
        <button type="submit" class="messages-send" data-send aria-label="Enviar mensaje" @disabled(!$context['can_send'])><x-lucide-arrow-up aria-hidden="true" /></button>
    </form>
    <p class="messages-local-help">Guardado confirma almacenamiento, no entrega al dispositivo. La lectura se confirma con el botón del historial.</p>
    <script type="application/json" data-file-rules>@json(['maxCount'=>config('messaging.max_attachments'),'maxBytes'=>config('attachments.max_bytes'),'types'=>config('attachments.types')])</script>
</div>
