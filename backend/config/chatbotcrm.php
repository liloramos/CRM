<?php

return [
    'providers' => [
        'whatsapp' => env('WHATSAPP_PROVIDER', 'fake'),
        'ai' => env('AI_PROVIDER', 'fake'),
        'printing' => env('PRINTING_PROVIDER', 'browser'),
    ],

    'integrations' => [
        'n8n' => [
            'webhook_base_url' => env('N8N_WEBHOOK_BASE_URL'),
        ],

        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        ],
    ],
];
