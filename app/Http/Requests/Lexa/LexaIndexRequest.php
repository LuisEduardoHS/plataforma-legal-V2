<?php

namespace App\Http\Requests\Lexa;

use Illuminate\Foundation\Http\FormRequest;

class LexaIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:256'],
            'size' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $extra = array_diff(array_keys($this->all()), ['cursor', 'size']);
            if ($extra !== []) {
                $validator->errors()->add('fields', 'Hay campos no permitidos.');
            }
        });
    }
}
