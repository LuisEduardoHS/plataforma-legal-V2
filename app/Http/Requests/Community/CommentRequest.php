<?php

namespace App\Http\Requests\Community;

class CommentRequest extends CommunityFormRequest
{
    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:'.config('community.comment_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u']]
            + ($this->isMethod('post') ? ['parent_id' => ['sometimes', 'nullable', 'integer', 'min:1']]
                : ['version' => ['required', 'integer', 'min:1', 'max:2147483647']]);
    }
}
