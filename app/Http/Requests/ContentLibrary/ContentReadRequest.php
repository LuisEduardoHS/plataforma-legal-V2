<?php

namespace App\Http\Requests\ContentLibrary;

use App\Enums\EditorialStatus;
use App\Enums\LegalResourceType;
use App\Enums\SavedContentType;
use Illuminate\Validation\Rule;

class ContentReadRequest extends ContentFormRequest
{
    public function rules(): array
    {
        $rules = ['cursor' => ['nullable', 'string', 'max:1024']];
        if ($this->routeIs('saved')) {
            return $rules + ['type' => ['nullable', Rule::enum(SavedContentType::class)]];
        }
        if ($this->routeIs('articles.workspace', 'resources.workspace')) {
            return $rules + ['all' => ['sometimes', 'boolean'], 'status' => ['nullable', Rule::enum(EditorialStatus::class)]];
        }
        if ($this->routeIs('articles', 'resources')) {
            $rules += ['title' => ['nullable', 'string', 'max:100'], 'author_id' => ['nullable', 'integer', 'min:1'],
                'category_id' => ['nullable', 'integer', 'min:1', 'exists:content_categories,id'],
                'tag_id' => ['nullable', 'integer', 'min:1', 'exists:tags,id'],
                'specialty_id' => ['nullable', 'integer', 'min:1', 'exists:legal_specialties,id']];
            if ($this->route('kind') === 'resource') {
                $rules['resource_type'] = ['nullable', Rule::enum(LegalResourceType::class)];
            }
        }

        return $rules;
    }
}
