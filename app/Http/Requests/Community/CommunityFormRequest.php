<?php

namespace App\Http\Requests\Community;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class CommunityFormRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('community.posts.store', 'community.posts.update')) {
            foreach (['replace_tags' => 'tag_ids', 'replace_attachments' => 'attachment_ids'] as $flag => $field) {
                if ($this->boolean($flag) && ! $this->exists($field)) {
                    $this->merge([$field => []]);
                }
            }
        }
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
                $validator->errors()->add('form', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        $this->attributes->set('community.input_validated', true);
        $this->container->make('request')->attributes->set('community.input_validated', true);
        $this->container->make('request')->merge($this->safe()->only(['tag_ids', 'attachment_ids', 'replace_tags', 'replace_attachments']));
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson() || $validator->errors()->has('version')) {
            parent::failedValidation($validator);
        }
        $old = [];
        foreach (array_keys($this->rules()) as $key) {
            $value = $this->input($key);
            if (in_array($key, ['replace_tags', 'replace_attachments'], true) && $value !== null && ! $validator->errors()->has($key)) {
                $old[$key] = $this->boolean($key) ? '1' : '0';
            } elseif ($key === 'parent_id' && $value !== null && ! $validator->errors()->has($key)) {
                $old[$key] = (string) (int) $value;
            } elseif ($key === 'version' && $value !== null) {
                // Version validation succeeded; preserve its original numeric value.
                $old[$key] = (string) (int) $value;
            } elseif (is_string($value)) {
                $old[$key] = mb_substr($value, 0, 10000);
            } elseif (in_array($key, ['tag_ids', 'attachment_ids'], true) && is_array($value)) {
                $old[$key] = array_values(array_slice(array_filter($value, fn ($id) => is_scalar($id) && ctype_digit((string) $id)), 0, 20));
            }
        }
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())->withErrors($validator)->withInput($old));
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('community.posts.store')) {
            return route('community.create');
        }
        if ($this->routeIs('community.posts.update')) {
            return route('community.edit', $this->route('post'));
        }
        if ($this->routeIs('community.comments.update')) {
            return route('community.comments.edit', $this->route('comment'));
        }
        if ($this->route('post')) {
            return route('community.show', $this->route('post'));
        }

        return route('community');
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Escribe el contenido.',
            'content.max' => 'El texto supera la longitud permitida.',
            'content.string' => 'Escribe un texto válido.',
            'content.regex' => 'El texto contiene caracteres no admitidos.',
            'version.required' => 'Abre de nuevo el contenido antes de realizar esta acción.',
            '*.integer' => 'Selecciona un identificador o versión válido.',
            '*.in' => 'Selecciona una opción válida.',
            '*.distinct' => 'No se permiten elementos repetidos.',
            'uploads.max' => 'Solo puedes asociar hasta cuatro archivos.',
            'uploads.*.mimes' => 'El formato del archivo no está admitido.',
            'uploads.*.max' => 'El archivo supera el tamaño permitido.',
        ];
    }
}
