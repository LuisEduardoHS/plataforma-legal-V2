<?php

namespace App\Http\Requests\ContentLibrary;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class ContentFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->isMethodSafe() || ($this->user()?->isActive() ?? false);
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
        $original = $this->container->make('request');
        $original->attributes->set('content.input_validated', true);
        // Domain validation happens after this FormRequest has been resolved;
        // Laravel flashes the original request on a domain validation redirect.
        $original->merge($this->safe()->only(['tag_ids', 'specialty_ids', 'attachment_ids']));
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->isMethodSafe() || $this->expectsJson() || $validator->errors()->has('version')) {
            parent::failedValidation($validator);
        }
        $old = [];
        foreach (array_keys($this->rules()) as $key) {
            $value = $this->input($key);
            if ($key === 'version' && $value !== null) {
                // Keep the submitted version even when another field failed.
                $old[$key] = (string) (int) $value;
            } elseif (is_string($value)) {
                $old[$key] = mb_substr($value, 0, 100000);
            } elseif (in_array($key, ['tag_ids', 'specialty_ids', 'attachment_ids'], true) && is_array($value)) {
                $old[$key] = array_values(array_slice(array_filter($value, fn ($id) => is_scalar($id) && ctype_digit((string) $id)), 0, 20));
            }
        }
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())->withErrors($validator)->withInput($old));
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('articles.store', 'resources.store', 'articles.update', 'resources.update')) {
            $prefix = $this->route('kind') === 'article' ? 'articles' : 'resources';

            return $this->route('entry') ? route($prefix.'.edit', $this->route('entry')) : route($prefix.'.create');
        }
        if ($this->routeIs('articles.transition', 'resources.transition')) {
            return route(($this->route('kind') === 'article' ? 'articles' : 'resources').'.show', $this->route('entry'));
        }

        return route('saved');
    }
}
