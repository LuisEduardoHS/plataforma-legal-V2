<?php

namespace App\Http\Requests\Lexa;

use Illuminate\Foundation\Http\FormRequest;

class StoreLexaMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxPrompt = (int) config('lexa.limits.max_prompt_chars', 5000);

        return [
            'content' => ['required', 'string', "max:{$maxPrompt}"],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $extra = array_diff(array_keys($this->all()), ['_token', 'content']);
            if ($extra !== []) {
                $validator->errors()->add('fields', 'Hay campos no permitidos.');
            }
        });
    }
}
