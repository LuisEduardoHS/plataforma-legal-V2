<?php

namespace App\Http\Controllers\Community;

use App\Http\Requests\Community\FeedRequest;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Tag;
use App\Services\Community\CommunityPresentation;
use App\Services\Community\CommunityQuery;
use Illuminate\Auth\AuthenticationException;

class FeedController extends CommunityController
{
    public function __construct(private CommunityQuery $query, private CommunityPresentation $presentation) {}

    public function index(FeedRequest $request)
    {
        $feed = $request->validated('feed') ?? 'recent';
        if ($feed !== 'recent' && ! $request->user()) {
            throw new AuthenticationException;
        }

        return $this->page('pages.community.index', function () use ($request, $feed) {
            $filters = array_filter($request->safe()->only(['category_id', 'specialty_id', 'tag_id']), fn ($value) => $value !== null);
            $user = $request->user();
            $page = match ($feed) {
                'following' => $this->query->following($user, $filters, cursor: $request->validated('cursor')),
                'mine' => $this->query->owned($user, $filters, cursor: $request->validated('cursor')),
                'moderation' => $this->query->moderation($user, $filters, cursor: $request->validated('cursor')),
                default => $this->query->recent($user, $filters, cursor: $request->validated('cursor')),
            };

            return $this->data($page, $request) + ['feed' => $feed, 'filters' => $filters, 'profileFeed' => false];
        });
    }

    public function user(FeedRequest $request, int $user)
    {
        return $this->page('pages.community.index', function () use ($request, $user) {
            $page = $this->query->profile($user, $request->user(), cursor: $request->validated('cursor'));

            return $this->data($page, $request) + ['feed' => 'recent', 'filters' => [], 'profileFeed' => true];
        });
    }

    private function data($page, FeedRequest $request): array
    {
        $page->appends($request->safe()->except(['cursor', 'comment_cursor', 'review', 'followers']));

        return [
            'posts' => $this->presentation->posts($page->items(), $request->user()), 'page' => $page,
            'categories' => ContentCategory::ordered()->get(['id', 'name', 'is_active']),
            'specialties' => LegalSpecialty::ordered()->get(['id', 'name', 'is_active']),
            'tags' => Tag::ordered()->get(['id', 'name', 'is_active']),
        ];
    }
}
