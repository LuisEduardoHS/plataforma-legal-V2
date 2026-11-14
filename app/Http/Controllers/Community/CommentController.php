<?php

namespace App\Http\Controllers\Community;

use App\Http\Requests\Community\CommentRequest;
use App\Http\Requests\Community\VersionRequest;
use App\Models\Comment;
use App\Services\Community\CommunityInteractionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CommentController extends CommunityController
{
    public function __construct(private CommunityInteractionService $interactions) {}

    public function store(CommentRequest $request, int $post)
    {
        $comment = $this->interactions->comment($request->user(), $post, $request->validated());

        return $this->saved($request, route('community.show', $post), 'Tu comentario se guardó.', ['id' => $comment->id, 'version' => $comment->version], 201);
    }

    public function edit(Request $request, int $comment)
    {
        return $this->page('pages.community.comment-edit', function () use ($request, $comment) {
            $model = Comment::with('post.author')->sharedLock()->findOrFail($comment);
            Gate::forUser($request->user())->authorize('interact', $model->post);
            Gate::forUser($request->user())->authorize('update', $model);

            return ['comment' => $model->only(['id', 'post_id', 'content', 'version'])];
        });
    }

    public function update(CommentRequest $request, int $comment)
    {
        $result = $this->interactions->editComment($request->user(), $comment, $request->validated('content'), $request->integer('version'));

        return $this->saved($request, route('community.show', $result->post_id), 'Tu comentario se actualizó.', ['id' => $result->id, 'version' => $result->version]);
    }

    public function destroy(VersionRequest $request, int $comment)
    {
        $this->interactions->deleteComment($request->user(), $comment, $request->integer('version'));

        return $this->saved($request, route('community'), 'El comentario fue eliminado.');
    }

    public function moderate(VersionRequest $request, int $comment)
    {
        $result = $this->interactions->moderateComment($request->user(), $comment, $request->validated('action') === 'hide', $request->integer('version'));

        return $this->saved($request, route('community.show', ['post' => $result->post_id, 'review' => 1]), 'Se actualizó la moderación del comentario.', ['id' => $result->id, 'version' => $result->version]);
    }
}
