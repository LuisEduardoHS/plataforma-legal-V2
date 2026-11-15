<?php

namespace App\Http\Requests\Messaging;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MessagingRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->routeIs('messages.send') && ! $this->exists('attachment_ids')) {
            $this->merge(['attachment_ids' => []]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->isActive() ?? false;
    }

    public function rules(): array
    {
        $version = ['required', 'integer', 'min:1', 'max:2147483647'];
        $text = ['nullable', 'string', 'max:'.config('messaging.message_max_length'), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];

        return match (true) {
            $this->routeIs('messages', 'messages.show', 'messages.updates') => ['cursor' => ['nullable', 'string', 'max:1024'],
                'history_cursor' => ['nullable', 'string', 'max:1024'], 'archived' => ['sometimes', 'boolean'],
                'participant_id' => ['nullable', 'integer', 'min:1']],
            $this->routeIs('messages.send') => ['content' => $text, 'client_token' => ['required', 'uuid'],
                'attachment_ids' => ['sometimes', 'array', 'list', 'max:'.config('messaging.max_attachments')],
                'attachment_ids.*' => ['required', 'integer', 'min:1', 'distinct']],
            $this->routeIs('messages.update') => ['content' => $text, 'version' => $version],
            $this->routeIs('messages.destroy') => ['version' => $version],
            $this->routeIs('messages.read') => ['message_id' => ['required', 'integer', 'min:1', 'max:999999999999999999']],
            $this->routeIs('messages.archive') => ['archived' => ['required', 'boolean']],
            $this->routeIs('messages.upload') => ['uploads' => ['required', 'array', 'list', 'min:1', 'max:'.config('messaging.max_attachments')],
                'uploads.*' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf,txt,mp3', 'max:102400']],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($this->all()), [...$keys, '_token', '_method'])) {
                $validator->errors()->add('form', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function passedValidation(): void
    {
        $original = $this->container->make('request');
        $original->attributes->set('messaging.input_validated', true);
        $original->merge($this->safe()->only(['attachment_ids']));
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->isMethodSafe() || $this->expectsJson() || $validator->errors()->has('version') || $validator->errors()->has('client_token')) {
            parent::failedValidation($validator);
        }
        $old = [];
        if (is_string($this->input('content'))) {
            $old['content'] = mb_substr($this->input('content'), 0, 10000);
        }
        foreach (['version', 'client_token'] as $key) {
            if ($this->has($key) && ! $validator->errors()->has($key)) {
                $old[$key] = $key === 'version' ? (string) (int) $this->input($key) : $this->input($key);
            }
        }
        $ids = $this->input('attachment_ids', []);
        if (is_array($ids)) {
            $old['attachment_ids'] = array_values(array_slice(array_filter($ids, fn ($id) => is_scalar($id) && ctype_digit((string) $id)), 0, 4));
        }
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())->withErrors($validator)->withInput($old));
    }

    protected function getRedirectUrl(): string
    {
        if ($this->routeIs('messages.update')) {
            return route('messages.edit', ['conversation' => $this->route('conversation'), 'message' => $this->route('message')]);
        }

        return $this->route('conversation') ? route('messages.show', $this->route('conversation')) : route('messages');
    }

    public function messages(): array
    {
        return ['content.max' => 'El mensaje admite hasta 10,000 caracteres.', 'content.string' => 'Escribe un texto válido.',
            'client_token.required' => 'Abre el formulario de envío para obtener su identificador.',
            'uploads.*.uploaded' => 'No se recibió el archivo completo. Reduce su tamaño e inténtalo de nuevo.',
            'uploads.*.mimes' => 'El formato del archivo no está admitido.'];
    }
}
