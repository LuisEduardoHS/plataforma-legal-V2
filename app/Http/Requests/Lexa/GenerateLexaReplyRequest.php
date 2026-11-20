<?php

namespace App\Http\Requests\Lexa;

use Illuminate\Foundation\Http\FormRequest;

class GenerateLexaReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'attempt_token' => ['nullable', 'uuid'],
            'state_id' => ['nullable', 'integer', 'exists:states,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $extra = array_diff(array_keys($this->all()), ['_token', 'attempt_token', 'state_id']);
            if ($extra !== []) {
                $validator->errors()->add('fields', 'Hay campos no permitidos.');
            }
        });
    }
}
