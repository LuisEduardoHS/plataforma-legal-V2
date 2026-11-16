<?php

namespace App\Http\Requests\Operations;

use App\Services\Operations\OperationsAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OperationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(OperationsAccess::class)->enter($this->user());
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'operations.users' => ['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'suspended', 'disabled', 'pending_deletion'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']],
            'operations.state' => ['status' => ['required', Rule::in(['active', 'suspended', 'disabled'])]] + $this->mutation(),
            'operations.permission' => ['permission' => ['required', Rule::in([...OperationsAccess::EDITORIAL, ...OperationsAccess::VERIFICATION, ...OperationsAccess::MODERATION])], 'action' => ['required', Rule::in(['grant', 'revoke'])]] + $this->mutation(),
            'operations.audit' => ['target_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']],
            'operations.moderation' => ['cursor' => ['nullable', 'string', 'max:500']],
            default => [],
        };
    }

    private function mutation(): array
    {
        return ['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'], 'reason' => ['required', 'string', 'min:10', 'max:2000']];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method'])) {
                $validator->errors()->add('form', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function getRedirectUrl(): string
    {
        return $this->route('account') ? route('operations.user', $this->route('account')) : route('operations.index');
    }
}
