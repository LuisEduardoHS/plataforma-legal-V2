<?php

namespace App\Http\Requests\Lexa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLexaConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxPrompt = (int) config('lexa.limits.max_prompt_chars', 5000);
        $maxTitle = (int) config('lexa.limits.max_title_chars', 72);

        return [
            'mode' => ['nullable', 'string', Rule::in(config('lexa.modes', ['orientation', 'contract', 'organize']))],
            'title' => ['nullable', 'string', "max:{$maxTitle}"],
            'content' => ['nullable', 'string', "max:{$maxPrompt}"],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $extra = array_diff(array_keys($this->all()), ['_token', 'mode', 'title', 'content']);
            if ($extra !== []) {
                $validator->errors()->add('fields', 'Hay campos no permitidos.');
            }
        });
    }
}
