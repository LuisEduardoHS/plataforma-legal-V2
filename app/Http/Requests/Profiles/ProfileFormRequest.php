<?php

namespace App\Http\Requests\Profiles;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ProfileFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('profile.professional.update') && $this->boolean('update_specialties') && ! $this->exists('specialty_ids')) {
            $this->merge(['specialty_ids' => []]);
        }
    }

    protected function passedValidation(): void
    {
        // Laravel flashes the original request when domain validation fails.
        $this->container->make('request')->merge($this->safe()->only(['specialty_ids', 'update_specialties']));
    }

    public function authorize(): bool
    {
        return $this->user()?->isActive() ?? false;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($this->all()), [...$allowed, '_token', '_method'])) {
                $validator->errors()->add('profile', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        // Invalid arrays must never reach Blade string fields; credentials never return.
        $old = [];
        foreach ($this->rules() as $key => $rules) {
            if ($key === 'municipality_id' && $this->exists($key) && $this->input($key) === null) {
                $old[$key] = null;
            } elseif ($key !== 'license_number' && is_string($this->input($key))) {
                $old[$key] = mb_substr($this->input($key), 0, 10000);
            }
        }
        if (is_array($this->input('specialty_ids'))) {
            $old['specialty_ids'] = array_slice(array_filter($this->input('specialty_ids'), fn ($id) => is_scalar($id)), 0, 20);
        }
        if ($this->exists('update_specialties') && ! $validator->errors()->has('update_specialties')) {
            $old['update_specialties'] = $this->boolean('update_specialties');
        }
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())->withErrors($validator)->withInput($old));
    }

    protected function commonRules(): array
    {
        return [
            'municipality_id' => ['sometimes', 'nullable', 'integer', 'exists:municipalities,id'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'visibility' => ['required', 'in:private,public'],
        ];
    }

    public function messages(): array
    {
        return [
            '*.max' => 'El campo supera la longitud permitida.',
            '*.string' => 'Escribe un texto válido.',
            '*.integer' => 'Selecciona un valor válido.',
            '*.exists' => 'Selecciona una opción existente.',
            '*.in' => 'Selecciona una opción válida.',
            '*.url' => 'Escribe una dirección que comience con https:// o http://.',
            '*.email' => 'Escribe un correo electrónico válido.',
            '*.regex' => 'El formato no es válido.',
            'visibility.required' => 'Selecciona la visibilidad del perfil.',
            'photo.required' => 'Selecciona una fotografía.',
            'photo.mimes' => 'Selecciona una imagen JPG, PNG, GIF o WebP.',
        ];
    }
}
