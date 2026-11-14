<?php

namespace App\Http\Requests\Profiles;

class SaveUserProfileRequest extends ProfileFormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => strtolower(trim($this->input('username')))]);
        }
    }

    public function rules(): array
    {
        return $this->commonRules() + [
            'display_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'username' => ['sometimes', 'nullable', 'string', 'regex:/\A[a-z][a-z0-9_]{2,29}\z/'],
            'biography' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}
