<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Requests\Messaging\MessagingRequest;
use App\Services\Messaging\ConversationService;
use App\Services\Messaging\MessagingQuery;

class ConversationController extends MessagingController
{
    public function __construct(private ConversationService $conversations, private MessagingQuery $query) {}

    public function start(MessagingRequest $request, int $recipient)
    {
        $chat = $this->conversations->start($request->user(), $recipient);

        return $this->completed($request, route('messages.show', $chat->id), 'Conversación disponible.', ['id' => $chat->id]);
    }

    public function archive(MessagingRequest $request, int $conversation)
    {
        $this->conversations->archive($request->user(), $conversation, $request->boolean('archived'));

        return $this->completed($request, route('messages', $request->boolean('archived') ? ['archived' => 1] : []), 'Se actualizó tu bandeja.');
    }

    public function hide(MessagingRequest $request, int $conversation)
    {
        $this->conversations->hide($request->user(), $conversation);

        return $this->completed($request, route('messages'), 'La conversación se ocultó solo para ti.');
    }

    public function read(MessagingRequest $request, int $conversation)
    {
        $this->conversations->markRead($request->user(), $conversation, $request->integer('message_id'));

        return $this->completed($request, route('messages.show', $conversation), 'Lectura confirmada.');
    }

    public function block(MessagingRequest $request, int $conversation)
    {
        $target = $this->query->state($request->user(), $conversation)['participant']['id'];
        $request->isMethod('delete') ? $this->conversations->unblock($request->user(), $target) : $this->conversations->block($request->user(), $target);

        return $this->completed($request, route('messages.show', $conversation), 'Se actualizó tu bloqueo.');
    }
}
