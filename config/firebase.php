<?php

$credentials = env('FIREBASE_CREDENTIALS');

if (! $credentials) {
    $credentials = storage_path('app/private/firebase-service-account.json');
} elseif (! str_starts_with($credentials, '/') && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $credentials)) {
    // Relative paths work from the project root in CLI, but not under PHP-FPM.
    $credentials = base_path($credentials);
}

return [
    'enabled' => (bool) env('FIREBASE_ENABLED', false),
    'credentials' => $credentials,
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'web' => [
        'apiKey' => env('FIREBASE_API_KEY'),
        'authDomain' => env('FIREBASE_AUTH_DOMAIN'),
        'projectId' => env('FIREBASE_PROJECT_ID'),
        'messagingSenderId' => env('FIREBASE_MESSAGING_SENDER_ID'),
        'appId' => env('FIREBASE_APP_ID'),
    ],
    'vapid_key' => env('FIREBASE_VAPID_KEY'),
];
