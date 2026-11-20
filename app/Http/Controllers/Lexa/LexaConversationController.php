<?php

namespace App\Http\Controllers\Lexa;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lexa\GenerateLexaReplyRequest;
use App\Http\Requests\Lexa\LexaIndexRequest;
use App\Http\Requests\Lexa\StoreLexaConversationRequest;
use App\Http\Requests\Lexa\StoreLexaMessageRequest;
use App\Services\Lexa\LexaConversationService;
use App\Services\Lexa\LexaQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LexaConversationController extends Controller
{
    public function __construct(
        private LexaQuery $query,
        private LexaConversationService $service,
    ) {}

    public function index(LexaIndexRequest $request): JsonResponse
    {
        $page = $this->query->conversations(
            $request->user(),
            $request->validated('cursor'),
            $request->validated('size'),
        );

        return response()->json([
            'conversations' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(StoreLexaConversationRequest $request): JsonResponse
    {
        $result = $this->service->create(
            $request->user(),
            $request->validated('mode') ?? 'orientation',
            $request->validated('title'),
            $request->validated('content'),
        );

        return response()->json($result, 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $conversation): JsonResponse
    {
        return response()->json([
            'conversation' => $this->query->conversation($request->user(), $conversation),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function messages(LexaIndexRequest $request, int $conversation): JsonResponse
    {
        $page = $this->query->messages(
            $request->user(),
            $conversation,
            $request->validated('cursor'),
            $request->validated('size'),
        );

        return response()->json([
            'messages' => $page->items(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'previous_cursor' => $page->previousCursor()?->encode(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function storeMessage(StoreLexaMessageRequest $request, int $conversation): JsonResponse
    {
        $result = $this->service->appendUserMessage(
            $request->user(),
            $conversation,
            $request->validated('content'),
        );

        return response()->json($result, 201)->header('Cache-Control', 'private, no-store');
    }

    public function generate(GenerateLexaReplyRequest $request, int $conversation): JsonResponse
    {
        $result = $this->service->generateReply(
            $request->user(),
            $conversation,
            $request->validated('attempt_token'),
        );

        $status = ($result['replayed'] ?? false) ? 200 : 201;

        return response()->json($result, $status)->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, int $conversation): JsonResponse
    {
        $this->service->delete($request->user(), $conversation);

        return response()->json(['deleted' => true])->header('Cache-Control', 'private, no-store');
    }
}
