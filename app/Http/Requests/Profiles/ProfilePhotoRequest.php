<?php

namespace App\Http\Requests\Profiles;

class ProfilePhotoRequest extends ProfileFormRequest
{
    public function rules(): array
    {
        return $this->isMethod('delete') ? [] : ['photo' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.intdiv(config('attachments.max_bytes.image'), 1024)]];
    }
}
