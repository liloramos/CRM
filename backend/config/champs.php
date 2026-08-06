<?php

return [
    'google_places' => [
        'enabled' => env('GOOGLE_PLACES_ENABLED', false),
        'api_key' => env('GOOGLE_PLACES_API_KEY'),
        'base_url' => env('GOOGLE_PLACES_BASE_URL', 'https://places.googleapis.com/v1'),
        'language' => env('GOOGLE_PLACES_LANGUAGE', 'pt-BR'),
        'region' => env('GOOGLE_PLACES_REGION', 'BR'),
        'timeout' => (int) env('GOOGLE_PLACES_TIMEOUT', 15),
        'max_results' => (int) env('GOOGLE_PLACES_MAX_RESULTS', 20),
    ],

    'meta' => [
        'enabled' => env('CHAMPS_META_ENABLED', false),
        'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
        'api_version' => env('META_GRAPH_API_VERSION'),
        'access_token' => env('META_GRAPH_ACCESS_TOKEN'),
        'instagram_account_id' => env('META_INSTAGRAM_ACCOUNT_ID'),
        'timeout' => (int) env('META_GRAPH_TIMEOUT', 15),
    ],
];
