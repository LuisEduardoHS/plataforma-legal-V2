<?php

return [
    'enabled_generation' => (bool) env('LEXA_GENERATION_ENABLED', false),

    'provider' => env('LEXA_PROVIDER', 'null'),

    'modes' => ['orientation', 'contract', 'organize'],

    'limits' => [
        'max_prompt_chars' => 5000,
        'max_title_chars' => 72,
        'conversations_per_page' => 20,
        'messages_per_page' => 30,
        'max_context_messages' => 12,
        'max_context_chars' => 24000,
        'legal_chunk_chars' => 1200,
        'legal_context_chars' => 6000,
        'max_output_tokens' => 1024,
    ],

    'system_instruction' => 'Eres LEXA, asistente de orientación jurídica informativa en México. Responde en español claro y estructurado, ayuda a ordenar dudas y preparar consultas profesionales, no sustituyes la asesoría de un abogado. Usa solamente la evidencia documental proporcionada para fundamentar afirmaciones jurídicas, reconociendo sus límites.',
];
