<?php

namespace App\Services\Attachments;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class AttachmentValidator
{
    public function validate(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            $this->reject();
        }

        $path = $file->getRealPath();
        $mime = $path ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        $size = $path ? filesize($path) : false;
        $type = config('attachments.types', [])[$mime ?: ''] ?? null;
        $extension = strtolower($file->getClientOriginalExtension());

        if (! $type || ! in_array($extension, $type['extensions'], true)) {
            $this->reject();
        }

        $limit = config('attachments.max_bytes.'.$type['category'], 0);
        if ($size === false || $size < 1 || $size > $limit) {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío o supera el tamaño permitido.']);
        }

        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            $this->reject();
        }

        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'archivo';

        return [
            'original_name' => mb_strcut($name, 0, 255, 'UTF-8'),
            'mime_type' => $mime,
            'extension' => $type['extensions'][0],
            'category' => $type['category'],
            'size_bytes' => $size,
            'sha256' => $hash,
        ];
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['file' => 'El tipo o la extensión del archivo no está permitido.']);
    }
}
