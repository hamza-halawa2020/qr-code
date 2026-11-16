<?php

return [
    'enabled' => (bool) env('FIREBASE_ENABLED', false),
    'credentials' => env('FIREBASE_CREDENTIALS', storage_path('app/private/firebase-service-account.json')),
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
