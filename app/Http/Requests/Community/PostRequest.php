<?php

namespace App\Http\Requests\Community;

use Illuminate\Contracts\Validation\Validator;

class PostRequest extends CommunityFormRequest
{
    public function rules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'string', 'max:'.config('community.post_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'],
            'visibility' => ['required', 'in:private,public'],
            'content_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'legal_specialty_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tag_ids' => ['sometimes', 'array', 'list', 'max:'.config('community.max_tags')],
            'tag_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'attachment_ids' => ['sometimes', 'array', 'list', 'max:'.config('community.max_attachments')],
            'attachment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'replace_tags' => ['sometimes', 'boolean'],
            'replace_attachments' => ['sometimes', 'boolean'],
            'uploads' => ['sometimes', 'array', 'list', 'max:'.config('community.max_attachments')],
            'uploads.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf,txt', 'max:'.intdiv(max(config('attachments.max_bytes')), 1024)],
            'intent' => ['required', $this->isMethod('post') ? 'in:draft,publish' : 'in:save,publish'],
            'version' => [$this->isMethod('post') ? 'prohibited' : 'required', 'integer', 'min:1', 'max:2147483647'],
        ];
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $existing = $this->input('attachment_ids', []);
            $uploads = $this->file('uploads', []);
            if (is_array($existing) && is_array($uploads) && count($existing) + count($uploads) > config('community.max_attachments')) {
                $validator->errors()->add('uploads', 'Conserva y carga como máximo cuatro archivos en total.');
            }
        }];
    }
}
