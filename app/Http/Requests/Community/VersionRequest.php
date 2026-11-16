<?php

namespace App\Http\Requests\Community;

use App\Models\Comment;
use App\Models\Post;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Facades\Gate;

class VersionRequest extends CommunityFormRequest
{
    private ?int $moderationPostId = null;

    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }
        if ($this->routeIs('community.posts.moderate')) {
            $post = Post::with('author')->findOrFail($this->route('post'));
            Gate::forUser($this->user())->authorize('moderate', $post);
        } elseif ($this->routeIs('community.comments.moderate')) {
            $comment = Comment::with('post.author')->findOrFail($this->route('comment'));
            Gate::forUser($this->user())->authorize('view', $comment->post);
            Gate::forUser($this->user())->authorize('moderate', $comment);
            $this->moderationPostId = $comment->post_id;
        }

        return true;
    }

    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1', 'max:2147483647']]
            + ($this->routeIs('community.*.moderate') ? ['action' => ['required', 'in:hide,show'], 'reason' => ['required', 'string', 'min:10', 'max:2000']] : []);
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->routeIs('community.*.moderate') && ! $this->expectsJson() && ! $validator->errors()->has('version')) {
            // Scope reason errors to the resource route, never a client-supplied ID.
            $suffix = $this->route('post') ? 'post-'.$this->route('post') : 'comment-'.$this->route('comment');
            $this->session()->flash('community.moderation_error', $suffix);
        }
        parent::failedValidation($validator);
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('community.comments.moderate') && $this->moderationPostId !== null) {
            return route('community.show', ['post' => $this->moderationPostId, 'review' => 1]);
        }

        return parent::getRedirectUrl();
    }
}
