<?php

return [
    // Only server configuration selects the disk. It must never serve public URLs.
    'disk' => env('ATTACHMENTS_DISK', 'attachments'),

    'max_bytes' => [
        'image' => 10 * 1024 * 1024,
        'video' => 100 * 1024 * 1024,
        'document' => 20 * 1024 * 1024,
        'other' => 20 * 1024 * 1024,
    ],

    // The first extension is canonical; browser MIME declarations are ignored.
    'types' => [
        'image/jpeg' => ['category' => 'image', 'extensions' => ['jpg', 'jpeg']],
        'image/png' => ['category' => 'image', 'extensions' => ['png']],
        'image/gif' => ['category' => 'image', 'extensions' => ['gif']],
        'image/webp' => ['category' => 'image', 'extensions' => ['webp']],
        'video/mp4' => ['category' => 'video', 'extensions' => ['mp4']],
        'video/webm' => ['category' => 'video', 'extensions' => ['webm']],
        'application/pdf' => ['category' => 'document', 'extensions' => ['pdf']],
        'text/plain' => ['category' => 'document', 'extensions' => ['txt']],
        'audio/mpeg' => ['category' => 'other', 'extensions' => ['mp3']],
    ],
];
