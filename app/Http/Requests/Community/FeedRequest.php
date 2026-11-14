<?php

namespace App\Http\Requests\Community;

use Illuminate\Foundation\Http\FormRequest;

class FeedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'feed' => ['sometimes', 'in:recent,following,mine,moderation'],
            'category_id' => ['nullable', 'integer', 'exists:content_categories,id'],
            'specialty_id' => ['nullable', 'integer', 'exists:legal_specialties,id'],
            'tag_id' => ['nullable', 'integer', 'exists:tags,id'],
            'cursor' => ['nullable', 'string', 'max:1024'],
            'comment_cursor' => ['nullable', 'string', 'max:1024'],
            'review' => ['sometimes', 'boolean'],
            'followers' => ['sometimes', 'boolean'],
        ];
    }
}
