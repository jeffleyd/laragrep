<?php

return [
    'api_key' => env('LARAGREP_API_KEY', env('OPENAI_API_KEY')),
    'base_url' => env('LARAGREP_BASE_URL', 'https://api.openai.com/v1/chat/completions'),
    'model' => env('LARAGREP_MODEL', 'gpt-3.5-turbo'),
    'system_prompt' => env('LARAGREP_SYSTEM_PROMPT', 'You are a helpful assistant that translates natural language questions into safe Laravel Eloquent queries. Always respond with valid JSON describing the steps to execute.'),
    'interpretation_prompt' => env('LARAGREP_INTERPRETATION_PROMPT', "You are an assistant that turns SQL query results into clear, business-oriented answers using the user's language."),
    'user_language' => env('LARAGREP_USER_LANGUAGE', 'pt-BR'),
    'connection' => env('LARAGREP_CONNECTION'),
    'exclude_tables' => array_values(array_filter(array_map('trim', explode(',', (string) env('LARAGREP_EXCLUDE_TABLES', ''))))),
    'database' => [
        'type' => env('LARAGREP_DATABASE_TYPE', 'MariaDB 10.6'),
        'name' => env('LARAGREP_DATABASE_NAME', env('DB_DATABASE', '')),
    ],
    'metadata' => [
        [
            'name' => 'users',
            'description' => 'Example table containing registered application users.',
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'bigint unsigned',
                    'description' => 'Primary key.',
                ],
                [
                    'name' => 'name',
                    'type' => 'varchar',
                    'description' => 'Full name of the user.',
                ],
                [
                    'name' => 'email',
                    'type' => 'varchar',
                    'description' => 'Unique email address.',
                ],
            ],
            'relationships' => [
                ['type' => 'hasMany', 'table' => 'posts', 'foreign_key' => 'user_id'],
            ],
        ],
        [
            'name' => 'posts',
            'description' => 'Example table with blog posts authored by users.',
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'bigint unsigned',
                    'description' => 'Primary key.',
                ],
                [
                    'name' => 'user_id',
                    'type' => 'bigint unsigned',
                    'description' => 'Foreign key referencing the author (users.id).',
                ],
                [
                    'name' => 'title',
                    'type' => 'varchar',
                    'description' => 'Title of the post.',
                ],
                [
                    'name' => 'published_at',
                    'type' => 'datetime',
                    'description' => 'Publication timestamp.',
                ],
            ],
            'relationships' => [
                ['type' => 'belongsTo', 'table' => 'users', 'foreign_key' => 'user_id'],
                ['type' => 'hasMany', 'table' => 'comments', 'foreign_key' => 'post_id'],
            ],
        ],
        [
            'name' => 'comments',
            'description' => 'Example table storing comments on posts.',
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'bigint unsigned',
                    'description' => 'Primary key.',
                ],
                [
                    'name' => 'post_id',
                    'type' => 'bigint unsigned',
                    'description' => 'Foreign key referencing the related post (posts.id).',
                ],
                [
                    'name' => 'user_id',
                    'type' => 'bigint unsigned',
                    'description' => 'Foreign key referencing the author of the comment (users.id).',
                ],
                [
                    'name' => 'body',
                    'type' => 'text',
                    'description' => 'Content of the comment.',
                ],
            ],
            'relationships' => [
                ['type' => 'belongsTo', 'table' => 'posts', 'foreign_key' => 'post_id'],
                ['type' => 'belongsTo', 'table' => 'users', 'foreign_key' => 'user_id'],
            ],
        ],
    ],
    'debug' => (bool) env('LARAGREP_DEBUG', false),
    'route' => [
        'prefix' => 'laragrep',
        'middleware' => [],
    ],
];
