<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Requests\Messaging\MessagingRequest;
use App\Services\Messaging\MessageService;
use App\Services\Messaging\MessagingQuery;
use Illuminate\Support\Str;

class MessageController extends MessagingController
{
    public function __construct(private MessageService $messages, private MessagingQuery $query) {}

    public function store(MessagingRequest $request, int $conversation)
    {
        $data = $request->safe()->only(['content', 'client_token', 'attachment_ids']);
        $message = $this->messages->send($request->user(), $conversation, $data);
        $key = $this->draftKey($request, $conversation);
        $this->composer($request, $conversation);
        $draft = $request->session()->get($key);
        $draft['attachment_ids'] = array_values(array_diff($draft['attachment_ids'], $data['attachment_ids'] ?? []));
        if ($draft['client_token'] === $data['client_token']) {
            $draft['client_token'] = (string) Str::uuid();
        }
        $request->session()->put($key, $draft);

        return $this->completed($request, route('messages.show', $conversation), 'Mensaje guardado.', [
            'id' => $message->id, 'version' => $message->version, 'status' => $message->status->value, 'next_token' => $draft['client_token'],
        ]);
    }

    public function edit(MessagingRequest $request, int $conversation, int $message)
    {
        return $this->page('pages.messages.edit', function () use ($request, $conversation, $message) {
            $context = $this->query->state($request->user(), $conversation);
            $item = $this->query->message($request->user(), $conversation, $message);
            abort_unless($context['can_send'] && $item['sender_id'] === $request->user()->id && $item['status'] === 'sent', 403);

            return compact('context', 'item');
        });
    }

    public function update(MessagingRequest $request, int $conversation, int $message)
    {
        $this->boundMessage($request, $conversation, $message);
        $result = $this->messages->edit($request->user(), $message, $request->validated('content'), $request->integer('version'));

        return $this->completed($request, route('messages.show', $conversation), 'Mensaje editado.', ['id' => $result->id, 'version' => $result->version]);
    }

    public function destroy(MessagingRequest $request, int $conversation, int $message)
    {
        $this->boundMessage($request, $conversation, $message);
        $this->messages->deleteForEveryone($request->user(), $message, $request->integer('version'));

        return $this->completed($request, route('messages.show', $conversation), 'Mensaje eliminado para todos.');
    }

    public function hide(MessagingRequest $request, int $conversation, int $message)
    {
        $this->boundMessage($request, $conversation, $message);
        $this->messages->hideForMe($request->user(), $message);

        return $this->completed($request, route('messages.show', $conversation), 'Mensaje oculto solo para ti.');
    }
}
