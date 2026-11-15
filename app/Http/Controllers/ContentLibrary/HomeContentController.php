<?php

namespace App\Http\Controllers\ContentLibrary;

use App\Enums\EditorialKind;
use App\Enums\SavedContentType;
use App\Services\ContentLibrary\EditorialQuery;
use App\Services\ContentLibrary\SavedItemService;
use Illuminate\Http\Request;

class HomeContentController extends ContentController
{
    public function __invoke(Request $request, EditorialQuery $query, SavedItemService $saved)
    {
        return $this->page('pages.home.home', function () use ($request, $query, $saved) {
            $reading = $query->recent(EditorialKind::Article, $request->user(), size: 1)->items()[0] ?? null;
            if ($reading) {
                $reading['saved'] = in_array($reading['id'], $saved->savedIds($request->user(), SavedContentType::Article, [$reading['id']]), true);
            }

            $dashboard = require resource_path('views/pages/home/demo.php');
            unset($dashboard['article']);

            return compact('reading', 'dashboard');
        });
    }
}
