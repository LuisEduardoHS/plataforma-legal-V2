<?php

namespace App\Http\Controllers\Community;

use App\Http\Requests\Community\FeedRequest;
use App\Http\Requests\Community\PostRequest;
use App\Http\Requests\Community\VersionRequest;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Post;
use App\Models\Tag;
use App\Services\Community\CommunityPresentation;
use App\Services\Community\CommunityQuery;
use App\Services\Community\PostService;
use App\Services\Community\PostSubmission;
use App\Services\Operations\ModerationOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PostController extends CommunityController
{
    public function __construct(private CommunityQuery $query, private PostService $posts) {}

    public function show(FeedRequest $request, int $post, CommunityPresentation $presentation)
    {
        return $this->page('pages.community.show', function () use ($request, $post, $presentation) {
            $data = $presentation->posts([$this->query->detail($post, $request->user())], $request->user())[0];
            $review = $request->boolean('review');
            $comments = ($data['status'] === 'published' || $review)
                ? $this->query->comments($post, $request->user(), cursor: $request->validated('comment_cursor'), review: $review) : null;
            if ($comments) {
                $comments->setCursorName('comment_cursor')->appends($review ? ['review' => 1] : []);
            }

            return ['post' => $data, 'comments' => $comments, 'review' => $review];
        });
    }

    public function create(Request $request)
    {
        Gate::forUser($request->user())->authorize('create', Post::class);

        return $this->page('pages.community.edit', fn () => $this->catalogs() + ['post' => null]);
    }

    public function edit(Request $request, int $post)
    {
        return $this->page('pages.community.edit', function () use ($request, $post) {
            $data = $this->query->detail($post, $request->user());
            abort_unless($data['can_edit'], 403);

            return $this->catalogs($data) + ['post' => $data];
        });
    }

    public function store(PostRequest $request, PostSubmission $submission)
    {
        return $this->submit($request, $submission);
    }

    public function update(PostRequest $request, int $post, PostSubmission $submission)
    {
        return $this->submit($request, $submission, $post);
    }

    private function submit(PostRequest $request, PostSubmission $submission, ?int $id = null)
    {
        $data = $request->safe()->only(['content', 'visibility', 'content_category_id', 'legal_specialty_id', 'tag_ids', 'attachment_ids']);
        foreach (['tags' => 'tag_ids', 'attachments' => 'attachment_ids'] as $flag => $field) {
            if ($request->boolean('replace_'.$flag)) {
                $data[$field] = $data[$field] ?? [];
            }
        }
        $post = $submission->submit($request->user(), $data, $request->file('uploads', []), $request->validated('intent'), $id, $id ? $request->integer('version') : null);

        return $this->saved($request, route('community.show', $post->id), 'La publicación se guardó correctamente.', [
            'id' => $post->id, 'version' => $post->version, 'status' => $post->status->value,
        ], $id ? 200 : 201);
    }

    public function publish(VersionRequest $request, int $post)
    {
        $result = $this->posts->publish($request->user(), $post, $request->integer('version'));

        return $this->saved($request, route('community.show', $post), 'La publicación fue publicada.', ['id' => $post, 'version' => $result->version]);
    }

    public function destroy(VersionRequest $request, int $post)
    {
        $this->posts->delete($request->user(), $post, $request->integer('version'));

        return $this->saved($request, route('community', ['feed' => 'mine']), 'La publicación fue eliminada.');
    }

    public function moderate(VersionRequest $request, int $post, ModerationOperations $moderation)
    {
        $result = $moderation->apply($request->user(), 'post', $post, $request->validated('action') === 'hide', $request->integer('version'), $request->validated('reason'));

        return $this->saved($request, route('community.show', $post), 'Se actualizó la moderación.', ['id' => $post, 'version' => $result->version]);
    }

    private function catalogs(?array $post = null): array
    {
        return [
            'categories' => ContentCategory::where(fn ($q) => $q->active()->orWhere('id', $post['category']['id'] ?? null))->ordered()->get(['id', 'name', 'is_active']),
            'specialties' => LegalSpecialty::where(fn ($q) => $q->active()->orWhere('id', $post['specialty']['id'] ?? null))->ordered()->get(['id', 'name', 'is_active']),
            'tags' => Tag::where(fn ($q) => $q->active()->orWhereIn('id', array_column($post['tags'] ?? [], 'id')))->ordered()->get(['id', 'name', 'is_active']),
        ];
    }
}
