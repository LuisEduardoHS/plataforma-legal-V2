<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class AuthFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token'])) {
                $validator->errors()->add('form', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        // Only bounded strings may return to Blade value attributes, even when
        // the rejected request contained arrays or oversized input.
        $old = array_map(fn (string $value) => mb_substr($value, 0, 255),
            array_filter($this->only('name', 'email'), 'is_string'));
        if (in_array($this->input('remember'), [true, false, 0, 1, '0', '1'], true)) {
            $old['remember'] = $this->boolean('remember');
        }

        // Never flash arbitrary input, credentials, tokens or hidden internal fields.
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($old));
    }

    protected function passwordRules(): array
    {
        return ['required', 'string', function (string $attribute, mixed $value, \Closure $fail): void {
            // Bcrypt must not silently truncate different passwords to the same prefix.
            if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                $fail('La contraseña es demasiado larga o contiene caracteres no admitidos.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Escribe tu nombre.',
            'name.string' => 'Escribe un nombre válido.',
            'name.max' => 'El nombre no puede superar 255 caracteres.',
            'name.regex' => 'El nombre contiene caracteres no admitidos.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.string' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo electrónico es demasiado largo.',
            'email.unique' => 'Este correo electrónico ya está registrado.',
            'password.required' => 'Escribe tu contraseña.',
            'password.string' => 'Escribe una contraseña válida.',
            'password.min' => 'La contraseña debe tener al menos 12 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
            'password_confirmation.required' => 'Confirma tu contraseña.',
            'password_confirmation.string' => 'Escribe una confirmación válida.',
            'remember.boolean' => 'La opción para recordar la sesión no es válida.',
        ];
    }
}
