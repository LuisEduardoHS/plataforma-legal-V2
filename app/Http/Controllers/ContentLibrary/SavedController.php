<?php

namespace App\Http\Controllers\ContentLibrary;

use App\Enums\SavedContentType;
use App\Http\Requests\ContentLibrary\ContentReadRequest;
use App\Http\Requests\ContentLibrary\SavedRequest;
use App\Services\ContentLibrary\SavedItemService;
use App\Services\ContentLibrary\SavedLibraryQuery;

class SavedController extends ContentController
{
    public function index(ContentReadRequest $request, SavedLibraryQuery $library)
    {
        return $this->page('pages.saved.index', function () use ($request, $library) {
            $type = $request->filled('type') ? SavedContentType::from($request->validated('type')) : null;
            $page = $library->library($request->user(), $type, cursor: $request->validated('cursor'));
            $page->appends($type ? ['type' => $type->value] : []);

            return compact('type', 'page');
        });
    }

    public function store(SavedRequest $request, string $type, int $target, SavedItemService $saved)
    {
        $saved->save($request->user(), SavedContentType::from($type), $target);

        return $this->completed($request, $this->target($type, $target), 'Contenido añadido a tus guardados.', ['saved' => true]);
    }

    public function destroy(SavedRequest $request, string $type, int $target, SavedItemService $saved)
    {
        $saved->remove($request->user(), SavedContentType::from($type), $target);

        return $this->completed($request, route('saved'), 'Contenido retirado de tus guardados.', ['saved' => false]);
    }

    private function target(string $type, int $id): string
    {
        return route(match ($type) {
            'post' => 'community.show', 'article' => 'articles.show', 'resource' => 'resources.show'
        }, $id);
    }
}
