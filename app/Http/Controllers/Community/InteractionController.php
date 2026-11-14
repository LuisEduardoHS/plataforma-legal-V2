<?php

namespace App\Http\Controllers\Community;

use App\Http\Requests\Community\FeedRequest;
use App\Http\Requests\Community\InteractionRequest;
use App\Services\Community\CommunityInteractionService;
use App\Services\Community\CommunityQuery;

class InteractionController extends CommunityController
{
    public function __construct(private CommunityInteractionService $interactions) {}

    public function react(InteractionRequest $request, int $post)
    {
        $this->interactions->react($request->user(), $post);

        return $this->saved($request, route('community.show', $post), 'Se agregó Me gusta.', ['liked' => true]);
    }

    public function unreact(InteractionRequest $request, int $post)
    {
        $this->interactions->unreact($request->user(), $post);

        return $this->saved($request, route('community.show', $post), 'Se retiró Me gusta.', ['liked' => false]);
    }

    public function follow(InteractionRequest $request, int $user)
    {
        $this->interactions->follow($request->user(), $user);

        return $this->saved($request, route('community'), 'Ahora sigues a esta cuenta.', ['following' => true]);
    }

    public function unfollow(InteractionRequest $request, int $user)
    {
        $this->interactions->unfollow($request->user(), $user);

        return $this->saved($request, route('community'), 'Dejaste de seguir a esta cuenta.', ['following' => false]);
    }

    public function connections(FeedRequest $request, int $user, CommunityQuery $query)
    {
        return $this->page('pages.community.connections', function () use ($request, $user, $query) {
            $summary = $query->connectionSummary($request->user(), $user);
            $page = $query->connections($request->user(), $user, $request->boolean('followers'), cursor: $request->validated('cursor'));
            $page->appends(['followers' => $request->boolean('followers') ? 1 : 0]);

            return ['summary' => $summary, 'page' => $page, 'targetId' => $user, 'followers' => $request->boolean('followers')];
        });
    }
}
