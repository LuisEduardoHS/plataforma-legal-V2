<?php

namespace App\Http\Requests\Profiles;

class SaveProfessionalProfileRequest extends ProfileFormRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // The review POST must flash an explicit empty selection when validation redirects.
        if ($this->routeIs('profile.professional.prepare') && $this->boolean('update_specialties') && ! $this->exists('specialty_ids')) {
            $this->merge(['specialty_ids' => []]);
        }
    }

    public function rules(): array
    {
        if ($this->routeIs('profile.professional.update') && $this->exists('pending_change')) {
            return ['pending_change' => ['required', 'string', 'max:262144'], 'confirm_material' => ['required', 'accepted']];
        }

        return $this->commonRules() + [
            'profile_version' => [$this->routeIs('profile.professional.prepare') ? 'required' : 'sometimes', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
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
