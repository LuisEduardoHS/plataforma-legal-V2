<?php

return [
    'enabled_generation' => (bool) env('LEXA_GENERATION_ENABLED', false),

    'modes' => ['orientation', 'contract', 'organize'],

    'limits' => [
        'max_prompt_chars' => 5000,
        'max_title_chars' => 72,
        'conversations_per_page' => 20,
        'messages_per_page' => 30,
        'max_context_messages' => 12,
        'max_output_tokens' => 1024,
    ],
];
