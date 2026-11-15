<?php

namespace App\Http\Requests\ContentLibrary;

use App\Enums\LegalResourceType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class EditorialRequest extends ContentFormRequest
{
    public function messages(): array
    {
        return ['title.required' => 'Escribe el título.', 'title.max' => 'El título admite hasta 200 caracteres.',
            'uploads.*.uploaded' => 'No se recibió el archivo completo. Reduce su tamaño y selecciónalo de nuevo.',
            'uploads.*.mimes' => 'El formato del archivo no está admitido.',
            'uploads.*.max' => 'El archivo supera el tamaño permitido.',
            'version.required' => 'Abre de nuevo el contenido antes de editar.'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['tag_ids', 'specialty_ids', 'attachment_ids'] as $key) {
            if (! $this->exists($key)) {
                $this->merge([$key => []]);
            }
        }
    }

    public function rules(): array
    {
        $text = fn ($limit) => ['string', 'max:'.config('content-library.'.$limit), 'regex:/\A[^\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
        $rules = ['title' => ['required', ...$text('title_max_length')],
            'content_category_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'intent' => ['required', 'in:save,publish'],
            'version' => [$this->route('entry') ? 'required' : 'prohibited', 'integer', 'min:1', 'max:2147483647']];
        foreach (['tag_ids' => 'max_tags', 'specialty_ids' => 'max_specialties', 'attachment_ids' => 'max_attachments'] as $field => $limit) {
            $rules[$field] = ['sometimes', 'array', 'list', 'max:'.config('content-library.'.$limit)];
            $rules[$field.'.*'] = ['required', 'integer', 'min:1', 'distinct'];
        }
        $rules += ['uploads' => ['sometimes', 'array', 'list', 'max:4'],
            'uploads.*' => ['file', 'mimes:jpg,jpeg,png,gif,webp,mp4,webm,pdf,txt,mp3', 'max:102400']];
        if ($this->route('kind') === 'article') {
            $rules += ['summary' => ['nullable', ...$text('summary_max_length')], 'body' => ['nullable', ...$text('body_max_length')]];
        } else {
            $rules += ['description' => ['nullable', ...$text('description_max_length')],
                'resource_type' => ['required', Rule::enum(LegalResourceType::class)],
                'external_url' => ['nullable', 'string', 'max:2048', 'url:https']];
        }

        return $rules;
    }

    public function after(): array
    {
        return [...parent::after(), function (Validator $validator): void {
            $ids = $this->input('attachment_ids', []);
            $uploads = $this->file('uploads', []);
            if (is_array($ids) && is_array($uploads) && count($ids) + count($uploads) > config('content-library.max_attachments')) {
                $validator->errors()->add('uploads', 'Conserva y carga como máximo cuatro archivos en total.');
            }
        }];
    }
}
