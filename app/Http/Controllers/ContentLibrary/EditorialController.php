<?php

namespace App\Http\Controllers\ContentLibrary;

use App\Enums\EditorialStatus;
use App\Enums\SavedContentType;
use App\Http\Requests\ContentLibrary\ContentReadRequest;
use App\Http\Requests\ContentLibrary\EditorialRequest;
use App\Http\Requests\ContentLibrary\TransitionRequest;
use App\Models\ContentCategory;
use App\Models\LegalSpecialty;
use App\Models\Tag;
use App\Services\ContentLibrary\EditorialMediaService;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\EditorialService;
use App\Services\ContentLibrary\EditorialSubmission;
use App\Services\ContentLibrary\SavedItemService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EditorialController extends ContentController
{
    public function __construct(private EditorialQuery $query, private SavedItemService $saved) {}

    public function index(ContentReadRequest $request)
    {
        return $this->page('pages.articles.index', function () use ($request) {
            $kind = $this->kind($request);
            $filters = array_filter($request->safe()->except('cursor'), fn ($value) => $value !== null && $value !== '');
            $page = $this->query->recent($kind, $request->user(), $filters, cursor: $request->validated('cursor'));
            $saved = $this->saved->savedIds($request->user(), SavedContentType::from($kind->value), array_column($page->items(), 'id'));
            $page->through(fn ($item) => $item + ['saved' => in_array($item['id'], $saved, true)])->appends($filters);

            return $this->catalogs(reading: true) + compact('kind', 'filters', 'page') + [
                'canCreate' => $request->user() && Gate::forUser($request->user())->allows('create', $kind->model()),
            ];
        });
    }

    public function show(ContentReadRequest $request, int $entry)
    {
        return $this->page('pages.articles.show', function () use ($request, $entry) {
            $kind = $this->kind($request);
            $item = $this->query->detail($kind, $entry, $request->user());
            $item['saved'] = in_array($entry, $this->saved->savedIds($request->user(), SavedContentType::from($kind->value), [$entry]), true);

            return compact('kind', 'item');
        });
    }

    public function workspace(ContentReadRequest $request)
    {
        return $this->page('pages.articles.workspace', function () use ($request) {
            $kind = $this->kind($request);
            $filters = array_filter($request->safe()->except('cursor'), fn ($value) => $value !== null && $value !== '');
            $page = $this->query->workspace($kind, $request->user(), $request->boolean('all'),
                $request->filled('status') ? EditorialStatus::from($request->validated('status')) : null, cursor: $request->validated('cursor'));
            $page->appends($filters);

            return compact('kind', 'page', 'filters') + ['canManage' => $request->user()->can($kind->permission().'.manage'),
                'canCreate' => Gate::forUser($request->user())->allows('create', $kind->model())];
        });
    }

    public function create(Request $request)
    {
        $kind = $this->kind($request);
        Gate::forUser($request->user())->authorize('create', $kind->model());

        return $this->page('pages.articles.edit', fn () => $this->catalogs() + ['kind' => $kind, 'item' => null]);
    }

    public function edit(Request $request, int $entry)
    {
        return $this->page('pages.articles.edit', function () use ($request, $entry) {
            $kind = $this->kind($request);
            $item = $this->query->detail($kind, $entry, $request->user());
            abort_unless($item['can_edit'], 403);

            return $this->catalogs($item) + compact('kind', 'item');
        });
    }

    public function store(EditorialRequest $request, EditorialSubmission $submission)
    {
        return $this->submit($request, $submission);
    }

    public function update(EditorialRequest $request, int $entry, EditorialSubmission $submission)
    {
        return $this->submit($request, $submission, $entry);
    }

    private function submit(EditorialRequest $request, EditorialSubmission $submission, ?int $entry = null)
    {
        $kind = $this->kind($request);
        $data = $request->safe()->except(['intent', 'version', 'uploads']);
        // These are complete HTML forms: absent checkbox groups mean removal.
        foreach (['tag_ids', 'specialty_ids', 'attachment_ids'] as $field) {
            $data[$field] ??= [];
        }
        $result = $submission->submit($kind, $request->user(), $data, $request->file('uploads', []),
            $request->validated('intent'), $entry, $entry ? $request->integer('version') : null);

        return $this->completed($request, route($kind->permission().'.show', $result->id), 'El contenido se guardó correctamente.',
            ['id' => $result->id, 'version' => $result->version, 'status' => $result->status->value], $entry ? 200 : 201);
    }

    public function transition(TransitionRequest $request, int $entry, EditorialService $editorial)
    {
        $kind = $this->kind($request);
        $result = $editorial->transition($kind, $request->user(), $entry, EditorialStatus::from($request->validated('status')), $request->integer('version'));
        $url = $result->trashed() ? route($kind->permission().'.workspace') : route($kind->permission().'.show', $entry);

        return $this->completed($request, $url, 'El estado editorial se actualizó.', ['id' => $result->id, 'version' => $result->version, 'status' => $result->status->value]);
    }

    public function history(ContentReadRequest $request, int $entry)
    {
        return $this->page('pages.articles.history', function () use ($request, $entry) {
            $kind = $this->kind($request);
            $page = $this->query->revisions($kind, $request->user(), $entry, cursor: $request->validated('cursor'));

            return compact('kind', 'entry', 'page');
        });
    }

    public function media(Request $request, int $entry, int $attachment, EditorialMediaService $media)
    {
        return $media->download($this->kind($request), $request->user(), $entry, $attachment);
    }

    private function catalogs(?array $item = null, bool $reading = false): array
    {
        $result = [];
        foreach (['categories' => [ContentCategory::class, [$item['category']['id'] ?? null]],
            'tags' => [Tag::class, array_column($item['tags'] ?? [], 'id')],
            'specialties' => [LegalSpecialty::class, array_column($item['specialties'] ?? [], 'id')]] as $key => [$model, $ids]) {
            $result[$key] = $model::when(! $reading, fn ($query) => $query->where(fn ($q) => $q->active()->orWhereIn('id', array_filter($ids))))
                ->ordered()->get(['id', 'name', 'is_active'])->map->only(['id', 'name', 'is_active'])->all();
        }

        return $result;
    }
}
