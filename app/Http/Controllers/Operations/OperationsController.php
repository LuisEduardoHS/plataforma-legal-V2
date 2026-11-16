<?php

namespace App\Http\Controllers\Operations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Operations\OperationsRequest;
use App\Models\OperationsEvent;
use App\Services\Community\CommunityPresentation;
use App\Services\Community\CommunityQuery;
use App\Services\Operations\AccountOperations;
use App\Services\Operations\OperationsAccess;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(private AccountOperations $accounts, private OperationsAccess $access) {}

    public function index(OperationsRequest $request)
    {
        return $this->page('index', fn () => ['capabilities' => $this->accounts->overview($request->user())]);
    }

    public function users(OperationsRequest $request)
    {
        return $this->page('users', fn () => ['users' => $this->accounts->users($request->user(), $request->validated()), 'filters' => $request->validated()]);
    }

    public function user(OperationsRequest $request, int $account)
    {
        return $this->page('user', fn () => $this->accounts->detail($request->user(), $account));
    }

    public function state(OperationsRequest $request, int $account)
    {
        $changed = $this->accounts->state($request->user(), $account, $request->validated('status'), $request->validated('version'), $request->validated('reason'));

        return $this->saved($request, $account, $changed);
    }

    public function permission(OperationsRequest $request, int $account)
    {
        $changed = $this->accounts->permission($request->user(), $account, $request->validated('permission'), $request->validated('action') === 'grant', $request->validated('version'), $request->validated('reason'));

        return $this->saved($request, $account, $changed);
    }

    public function audit(OperationsRequest $request)
    {
        return $this->page('audit', function () use ($request) {
            $this->access->authorize($this->accounts->current($request->user()), 'operations.audit');
            $query = OperationsEvent::query()->select(['id', 'actor_id', 'target_user_id', 'action', 'resource_type', 'resource_id', 'permission_name', 'before_state', 'after_state', 'reason', 'source', 'created_at']);
            if ($request->filled('target_id')) {
                $query->where('target_user_id', $request->integer('target_id'));
            }

            return ['events' => $query->orderByDesc('id')->paginate(20)->withQueryString()];
        });
    }

    public function moderation(OperationsRequest $request, CommunityQuery $query, CommunityPresentation $presentation)
    {
        return $this->page('moderation', function () use ($request, $query, $presentation) {
            $actor = $this->accounts->current($request->user());
            $permissions = $this->access->effective($actor);
            abort_unless(array_intersect(OperationsAccess::MODERATION, $permissions), 403);
            $posts = in_array('posts.moderate', $permissions, true)
                ? $query->moderation($actor, cursor: $request->validated('cursor'))
                : $query->recent($actor, cursor: $request->validated('cursor'));
            $posts->through(fn ($post) => $presentation->posts([$post], $actor)[0]);

            return ['posts' => $posts, 'can_review_comments' => in_array('comments.moderate', $permissions, true)];
        });
    }

    private function page(string $view, callable $data)
    {
        return response()->view('pages.operations.'.$view, DB::transaction($data))->header('Cache-Control', 'private, no-store');
    }

    private function saved(OperationsRequest $request, int $id, bool $changed)
    {
        $message = $changed ? 'El cambio y su auditoría se guardaron.' : 'El estado solicitado ya estaba vigente. No se duplicaron cambios.';

        return ($request->expectsJson() ? response()->json(['message' => $message, 'changed' => $changed]) : to_route('operations.user', $id)->with('status', $message))->header('Cache-Control', 'private, no-store');
    }
}
