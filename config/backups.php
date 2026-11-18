<?php

return [
    'enabled' => (bool) env('QUEUE_BACKUPS_ENABLED', false),
    'directory' => storage_path('app/private/backups'),
    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),
];
