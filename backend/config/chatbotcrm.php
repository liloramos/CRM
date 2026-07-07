<?php

return [
    'providers' => [
        'whatsapp' => env('WHATSAPP_PROVIDER', 'fake'),
        'ai' => env('AI_PROVIDER', 'fake'),
        'printing' => env('PRINTING_PROVIDER', 'browser'),
    ],

    'whatsapp' => [
        'provider' => env('WHATSAPP_PROVIDER', 'fake'),

        'fake' => [
            'verify_token' => env('FAKE_WHATSAPP_VERIFY_TOKEN'),
        ],

        'meta' => [
            'token' => env('META_WHATSAPP_TOKEN'),
            'phone_number_id' => env('META_WHATSAPP_PHONE_NUMBER_ID'),
            'business_account_id' => env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'),
            'verify_token' => env('META_WHATSAPP_VERIFY_TOKEN'),
            'app_secret' => env('META_WHATSAPP_APP_SECRET'),
            'api_version' => env('META_WHATSAPP_API_VERSION', 'v20.0'),
            'graph_url' => env('META_WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        ],
    ],

    'ai' => [
        'provider' => env('AI_PROVIDER', 'fake'),
        'automation_enabled' => env('AI_AUTOMATION_ENABLED', true),
        'allow_auto_send' => env('AI_ALLOW_AUTO_SEND', false),

        'n8n' => [
            'webhook_path' => env('N8N_AI_WEBHOOK_PATH'),
        ],
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
