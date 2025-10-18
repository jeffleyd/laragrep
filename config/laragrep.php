<?php

return [
    'api_key' => env('LARAGREP_API_KEY', env('OPENAI_API_KEY')),
    'base_url' => env('LARAGREP_BASE_URL', 'https://api.openai.com/v1/chat/completions'),
    'model' => env('LARAGREP_MODEL', 'gpt-3.5-turbo'),
    'system_prompt' => env('LARAGREP_SYSTEM_PROMPT', 'You are a helpful assistant that translates natural language questions into safe Laravel Eloquent queries. Always respond with valid JSON describing the steps to execute.'),
    'connection' => env('LARAGREP_CONNECTION'),
    'exclude_tables' => array_values(array_filter(array_map('trim', explode(',', (string) env('LARAGREP_EXCLUDE_TABLES', ''))))),
    'metadata' => [],
    'route' => [
        'prefix' => 'laragrep',
        'middleware' => [],
    ],
];
