<?php

namespace App\Http\Requests\Profiles;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class DirectoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'specialty_id' => ['nullable', 'integer', Rule::exists('legal_specialties', 'id')->where('is_active', true)],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
            'municipality_id' => ['nullable', 'integer', Rule::exists('municipalities', 'id')->when($this->filled('state_id'), fn ($rule) => $rule->where('state_id', $this->input('state_id')))],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        // A bad GET must not redirect back into the same invalid URL.
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }
        throw new HttpResponseException(redirect()->route('professionals')->withErrors(['filters' => 'Revisa los filtros de búsqueda.']));
    }
}
