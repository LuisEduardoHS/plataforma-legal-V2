@if ($history->nextPageUrl() || $history->previousPageUrl())<nav class="messages-pagination" aria-label="Paginación del historial">@if ($history->nextPageUrl())<a href="{{ $history->nextPageUrl() }}">Mensajes anteriores</a>@endif @if ($history->previousPageUrl())<a href="{{ $history->previousPageUrl() }}">Más recientes</a>@endif<a href="{{ route('messages.show', $context['id']) }}">Volver al historial reciente</a></nav>@endif
@forelse (array_reverse($history->items()) as $message)
    <article class="messages-bubble {{ $message['sender_id'] === $actorId ? 'is-own' : '' }}" data-message-id="{{ $message['id'] }}">
        <p>{{ $message['status'] === 'deleted' ? 'Mensaje eliminado para todos' : $message['content'] }}</p>
        <div class="messages-message-files">@foreach ($message['attachments'] as $file)<a class="messages-file" href="{{ route('messages.download', ['conversation' => $context['id'], 'message' => $message['id'], 'attachment' => $file['id']]) }}" data-turbo="false"><x-lucide-file-text aria-hidden="true" /><span><strong>{{ $file['name'] }}</strong><small>{{ strtoupper($file['extension']) }} · {{ max(1, (int) ceil($file['size_bytes'] / 1024)) }} KiB · Descargar</small></span><x-lucide-download aria-hidden="true" /></a>@endforeach</div>
        <footer><time datetime="{{ Illuminate\Support\Carbon::parse($message['sent_at'])->toIso8601String() }}">{{ $message['sent_at'] }}</time>@if ($message['edited_at'])<span>Editado</span>@endif @if ($message['sender_id'] === $actorId && $message['status'] === 'sent')<span>{{ ($message['read_by_other'] ?? false) ? 'Leído' : 'Guardado' }}</span>@endif</footer>
        <details class="messages-message-actions"><summary>Acciones del mensaje</summary><div>
            @if ($message['sender_id'] === $actorId && $message['status'] === 'sent')
                @if ($context['can_send'])<a href="{{ route('messages.edit', ['conversation' => $context['id'], 'message' => $message['id']]) }}">Editar</a>@endif
                <form method="POST" action="{{ route('messages.destroy', ['conversation' => $context['id'], 'message' => $message['id']]) }}">@csrf @method('DELETE')<input type="hidden" name="version" value="{{ $message['version'] }}"><button>Eliminar para todos</button></form>
            @endif
            <form method="POST" action="{{ route('messages.message.hide', ['conversation' => $context['id'], 'message' => $message['id']]) }}">@csrf<button>Ocultar solo para mí</button></form>
        </div></details>
    </article>
@empty<p class="messages-empty-chat">No hay mensajes accesibles en esta página. Los mensajes ocultos no se recuperan al abrir de nuevo la conversación.</p>@endforelse
@if (count($history))
    @php($readThrough = max(array_column($history->items(), 'id')))
    <form class="messages-read-confirm" method="POST" action="{{ route('messages.read', $context['id']) }}">@csrf<input type="hidden" name="message_id" value="{{ $readThrough }}"><button class="messages-action" @disabled($readThrough <= ($context['last_read_message_id'] ?? 0))>Marcar como leído hasta el último mensaje mostrado</button><p class="messages-muted">Avanza también sobre los mensajes anteriores accesibles.</p></form>
@endif
