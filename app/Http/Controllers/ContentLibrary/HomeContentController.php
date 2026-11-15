<?php

namespace App\Http\Controllers\ContentLibrary;

use App\Enums\EditorialKind;
use App\Enums\SavedContentType;
use App\Services\Community\CommunityQuery;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\SavedItemService;
use Illuminate\Http\Request;

class HomeContentController extends ContentController
{
    public function __invoke(Request $request, EditorialQuery $query, SavedItemService $saved, CommunityQuery $community)
    {
        return $this->page('pages.home.home', function () use ($request, $query, $saved, $community) {
            $reading = $query->recent(EditorialKind::Article, $request->user(), size: 1)->items()[0] ?? null;
            if ($reading) {
                $reading['saved'] = in_array($reading['id'], $saved->savedIds($request->user(), SavedContentType::Article, [$reading['id']]), true);
            }

            $feed = $community->recent($request->user(), size: 2)->items();
            $savedPosts = $saved->savedIds($request->user(), SavedContentType::Post, array_column($feed, 'id'));
            $feed = array_map(fn ($post) => $post + ['saved' => in_array($post['id'], $savedPosts, true)], $feed);

            return compact('reading', 'feed');
        });
    }
}
