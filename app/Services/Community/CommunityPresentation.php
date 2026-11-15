<?php

namespace App\Services\Community;

use App\Enums\SavedContentType;
use App\Models\PostReaction;
use App\Models\User;
use App\Services\ContentLibrary\SavedItemService;
use Illuminate\Support\Facades\DB;

class CommunityPresentation
{
    public function posts(array $posts, ?User $viewer): array
    {
        $liked = $viewer ? PostReaction::where('user_id', $viewer->id)->where('type', 'like')->whereIn('post_id', array_column($posts, 'id'))->pluck('post_id')->all() : [];
        $followed = $viewer ? DB::table('user_follows')->where('follower_id', $viewer->id)->whereIn('followed_id', array_column(array_column($posts, 'author'), 'id'))->pluck('followed_id')->all() : [];
        $saved = app(SavedItemService::class)->savedIds($viewer, SavedContentType::Post, array_column($posts, 'id'));

        return array_map(function ($post) use ($viewer, $liked, $followed, $saved) {
            $post['saved'] = in_array($post['id'], $saved, true);
            $post['can_save'] = $viewer !== null && $post['status'] === 'published' && $post['author']['active'];
            $post['liked'] = in_array($post['id'], $liked, true);
            $post['following'] = in_array($post['author']['id'], $followed, true);
            $post['can_follow'] = $viewer && $viewer->id !== $post['author']['id'] && $post['author']['active'];

            return $post;
        }, $posts);
    }
}
