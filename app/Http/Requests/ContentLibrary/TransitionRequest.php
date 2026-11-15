<?php

namespace App\Http\Requests\ContentLibrary;

use App\Enums\EditorialStatus;
use Illuminate\Validation\Rule;

class TransitionRequest extends ContentFormRequest
{
    public function rules(): array
    {
        return ['version' => ['required', 'integer', 'min:1', 'max:2147483647'], 'status' => ['required', Rule::enum(EditorialStatus::class)]];
    }
}
