<?php

namespace App\Http\Requests\Community;

class VersionRequest extends CommunityFormRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1', 'max:2147483647']]
            + ($this->routeIs('community.*.moderate') ? ['action' => ['required', 'in:hide,show']] : []);
    }
}
