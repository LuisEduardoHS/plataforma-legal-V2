<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rule;

class RegisterRequest extends AuthFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => [...$this->passwordRules(), 'min:12', 'confirmed'],
            'password_confirmation' => $this->passwordRules(),
        ];
    }
}
