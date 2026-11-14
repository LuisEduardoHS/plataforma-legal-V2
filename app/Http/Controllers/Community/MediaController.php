<?php

namespace App\Http\Controllers\Community;

use App\Services\Community\CommunityMediaService;
use Illuminate\Http\Request;

class MediaController extends CommunityController
{
    public function __invoke(Request $request, int $post, int $attachment, CommunityMediaService $media)
    {
        return $media->download($request->user(), $post, $attachment);
    }
}
