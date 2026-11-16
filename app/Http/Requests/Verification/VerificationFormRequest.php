<?php

namespace App\Http\Requests\Verification;

use App\Enums\VerificationEvidenceType;
use App\Services\Verification\VerificationWorkflow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class VerificationFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()?->isActive()) {
            return false;
        }
        if ($this->route('dossier')) {
            $operation = match (true) {
                $this->routeIs('verification.review.show') => 'review',
                $this->routeIs('verification.approve', 'verification.reject') => 'decide',
                $this->routeIs('verification.revoke') => 'revoke',
                $this->routeIs('verification.history') => 'audit',
                $this->routeIs('verification.download') => 'readEvidence',
                default => 'owner',
            };
            app(VerificationWorkflow::class)->authorize($this->user(), (int) $this->route('dossier'), $operation);
        }

        return true;
    }

    public function rules(): array
    {
        if ($this->isMethodSafe()) {
            if ($this->routeIs('verification', 'verification.review.index')) {
                return ['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]
                    + ($this->routeIs('verification.review.index') ? ['status' => ['sometimes', 'in:pending,approved,rejected,revoked,invalidated']] : []);
            }

            return [];
        }
        $rules = $this->route('dossier') ? ['version' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']] : [];
        if ($this->routeIs('verification.evidence.store')) {
            $rules += [
                'type' => ['required', Rule::enum(VerificationEvidenceType::class)],
                'file' => ['required', 'file', 'max:'.(int) floor(config('verification.max_evidence_bytes') / 1024)],
            ];
        }
        if ($this->routeIs('verification.approve', 'verification.reject', 'verification.revoke')) {
            $rules['reason'] = ['required', 'string', 'min:10', 'max:2000', 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
        }

        return $rules;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), [...array_keys($this->rules()), '_token', '_method'])) {
                $validator->errors()->add('form', 'El formulario contiene campos no permitidos.');
            }
        }];
    }

    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson() || $this->isMethodSafe()) {
            parent::failedValidation($validator);
        }
        $old = [];
        foreach (['version' => 64, 'type' => 50, 'reason' => 2000] as $field => $limit) {
            if (is_string($this->input($field))) {
                $old[$field] = mb_substr($this->input($field), 0, $limit);
            }
        }
        throw new HttpResponseException(redirect()->to($this->getRedirectUrl())->withErrors($validator)->withInput($old));
    }

    protected function getRedirectUrl(): string
    {
        if (! $this->route('dossier')) {
            return route('verification');
        }

        return route($this->routeIs('verification.approve', 'verification.reject', 'verification.revoke')
            ? 'verification.review.show' : 'verification.show', $this->route('dossier'));
    }

    public function messages(): array
    {
        return [
            'version.*' => 'Abre de nuevo el expediente antes de enviar el formulario.',
            'type.*' => 'Selecciona un tipo de evidencia válido.',
            'file.required' => 'Selecciona un documento privado.',
            'file.file' => 'Selecciona un archivo válido.',
            'file.max' => 'El documento supera el límite de evidencia.',
            'reason.*' => 'Escribe una observación comunicable de 10 a 2000 caracteres, sin controles no admitidos.',
            'page.*' => 'Selecciona una página válida.',
            'status.*' => 'Selecciona un estado de revisión válido.',
        ];
    }
}
