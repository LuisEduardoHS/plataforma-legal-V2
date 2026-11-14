<?php

namespace App\Http\Requests\Profiles;

class SaveProfessionalProfileRequest extends ProfileFormRequest
{
    public function rules(): array
    {
        return $this->commonRules() + [
            'headline' => ['sometimes', 'nullable', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'license_number' => ['sometimes', 'nullable', 'string', 'regex:/\A[0-9]{5,12}\z/'],
            'years_experience' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:80'],
            'attention_mode' => ['sometimes', 'nullable', 'in:in_person,remote,hybrid'],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'regex:/\A[+0-9() .-]{7,30}\z/'],
            'contact_visible' => ['required', 'boolean'],
            'specialty_ids' => ['sometimes', 'array', 'max:20'],
            'specialty_ids.*' => ['required', 'integer', 'distinct'],
            'update_specialties' => ['sometimes', 'boolean'],
        ];
    }
}
