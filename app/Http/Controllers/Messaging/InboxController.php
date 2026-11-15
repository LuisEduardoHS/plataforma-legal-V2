<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Requests\Messaging\MessagingRequest;
use App\Services\Messaging\MessagingQuery;
use Illuminate\Support\Facades\DB;

class InboxController extends MessagingController
{
    public function __construct(private MessagingQuery $query) {}

    public function index(MessagingRequest $request)
    {
        return response()->view('pages.messages.index', $this->data($request))->header('Cache-Control', 'private, no-store');
    }

    public function show(MessagingRequest $request, int $conversation)
    {
        return response()->view('pages.messages.index', $this->data($request, $conversation))->header('Cache-Control', 'private, no-store');
    }

    private function data(MessagingRequest $request, ?int $conversation = null, bool $poll = false): array
    {
        $archived = $request->boolean('archived');
        $filters = array_filter($request->safe()->only(['archived', 'participant_id']), fn ($value) => $value !== null);
        // Release the inbox's shared locks before taking the selected pair's
        // exclusive locks. Both DTO snapshots retain the domain's current reads.
        $inbox = DB::transaction(fn () => $this->query->inbox($request->user(), $archived, cursor: $poll ? null : $request->validated('cursor'),
            participantId: $request->filled('participant_id') ? $request->integer('participant_id') : null));
        $inbox->appends($filters);
        [$context, $history, $composer] = $conversation ? DB::transaction(fn () => [
            $this->query->state($request->user(), $conversation),
            $this->query->history($request->user(), $conversation, cursor: $poll ? null : $request->validated('history_cursor')),
            $poll ? null : $this->composer($request, $conversation),
        ]) : [null, null, null];
        if ($history) {
            $history->setCursorName('history_cursor')->appends($filters);
        }

        return compact('archived', 'inbox', 'context', 'history', 'filters', 'composer') + [
            'actorId' => $request->user()->id,
            'historical' => ! $poll && $request->filled('history_cursor'),
        ];
    }

    public function updates(MessagingRequest $request, int $conversation)
    {
        $data = $this->data($request, $conversation, true);

        return response()->json([
            'conversation_id' => $conversation, 'can_send' => $data['context']['can_send'],
            'history_html' => view('pages.messages.partials.history', $data)->render(),
            'inbox_html' => view('pages.messages.partials.inbox', $data)->render(),
            'information_html' => view('pages.messages.partials.information', $data)->render(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
