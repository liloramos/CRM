<?php

$whatsappAccessToken = env('WHATSAPP_ACCESS_TOKEN', env('META_WHATSAPP_TOKEN'));
$whatsappAppId = env('WHATSAPP_APP_ID', env('META_WHATSAPP_APP_ID'));
$whatsappPhoneNumberId = env('WHATSAPP_PHONE_NUMBER_ID', env('META_WHATSAPP_PHONE_NUMBER_ID'));
$whatsappBusinessAccountId = env('WHATSAPP_BUSINESS_ACCOUNT_ID', env('META_WHATSAPP_BUSINESS_ACCOUNT_ID'));
$whatsappVerifyToken = env('WHATSAPP_VERIFY_TOKEN', env('META_WHATSAPP_VERIFY_TOKEN'));
$whatsappApiVersion = env('WHATSAPP_API_VERSION', env('META_WHATSAPP_API_VERSION', 'v20.0'));
$whatsappAppSecret = env('META_WHATSAPP_APP_SECRET', env('WHATSAPP_APP_SECRET'));
$whatsappCaBundle = env('WHATSAPP_CA_BUNDLE', env('CURL_CA_BUNDLE'));
$whatsappProvider = env(
    'WHATSAPP_PROVIDER',
    $whatsappAccessToken && $whatsappPhoneNumberId && $whatsappVerifyToken ? 'meta' : 'fake',
);

return [
    'providers' => [
        'whatsapp' => $whatsappProvider,
        'ai' => env('AI_PROVIDER', 'fake'),
        'printing' => env('PRINTING_PROVIDER', 'browser'),
    ],

    'orders' => [
        'allow_destructive_test_cleanup' => env('ALLOW_DESTRUCTIVE_TEST_CLEANUP', false),
    ],

    'whatsapp' => [
        'provider' => $whatsappProvider,
        'demo_data_enabled' => env('DEMO_DATA_ENABLED', false),

        'media' => [
            'ffmpeg_binary' => env('WHATSAPP_FFMPEG_BINARY', 'ffmpeg'),
            'ffprobe_binary' => env('WHATSAPP_FFPROBE_BINARY', 'ffprobe'),
        ],

        'fake' => [
            'verify_token' => env('FAKE_WHATSAPP_VERIFY_TOKEN'),
        ],

        'meta' => [
            'token' => $whatsappAccessToken,
            'app_id' => $whatsappAppId,
            'phone_number_id' => $whatsappPhoneNumberId,
            'business_account_id' => $whatsappBusinessAccountId,
            'verify_token' => $whatsappVerifyToken,
            'app_secret' => $whatsappAppSecret,
            'api_version' => $whatsappApiVersion,
            'graph_url' => env('WHATSAPP_GRAPH_URL', env('META_WHATSAPP_GRAPH_URL', 'https://graph.facebook.com')),
            'ca_bundle' => $whatsappCaBundle,
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
