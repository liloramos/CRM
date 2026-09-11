<?php

return [
    'email' => env('SUPPORT_EMAIL'),
    'whatsapp_number' => preg_replace('/\D+/', '', (string) env('SUPPORT_WHATSAPP_NUMBER')) ?: null,
];
