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
        'max_output_tokens' => 1024,
    ],

    'system_instruction' => 'Eres LEXA, asistente de orientación jurídica informativa en México. Responde en español claro y estructurado, ayuda a ordenar dudas y preparar consultas profesionales, y aclara que todavía no cuentas con fuentes jurídicas oficiales verificadas conectadas ni sustituyes la asesoría de un abogado.',
];
