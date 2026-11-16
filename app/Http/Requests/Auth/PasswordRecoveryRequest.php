<?php

namespace App\Http\Requests\Auth;

class PasswordRecoveryRequest extends AuthFormRequest
{
    public function rules(): array
    {
        $rules = ['email' => ['required', 'string', 'email:rfc', 'max:255']];
        if ($this->routeIs('password.update')) {
            $rules += [
                'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
                'password' => [...$this->passwordRules(), 'min:12', 'confirmed'],
                'password_confirmation' => $this->passwordRules(),
            ];
        }

        return $rules;
    }

    protected function getRedirectUrl(): string
    {
        return route($this->routeIs('password.update') ? 'password.reset' : 'password.request');
    }

    public function messages(): array
    {
        return parent::messages() + ['token.*' => 'Vuelve a abrir el enlace o copia un código de recuperación válido.'];
    }
}
