<?php

use App\Services\DatabaseBackupService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local') || DB::getDriverName() !== 'mysql') {
    throw new RuntimeException('This restore check requires local MySQL.');
}
$backup = app(DatabaseBackupService::class)->create();
$sql = file_get_contents($backup['path']);
if (preg_match('/\b(?:CREATE|DROP)\s+DATABASE\b|\bUSE\s+[`a-zA-Z_]/i', $sql)) {
    throw new RuntimeException('Dump includes database switching; restore check refused.');
}
$temporaryDatabase = 'qr_restore_check_'.str_replace('-', '', (string) Str::uuid());
if (! preg_match('/^qr_restore_check_[a-f0-9]{32}$/', $temporaryDatabase)) {
    throw new RuntimeException('Invalid temporary database name.');
}
$created = false;
try {
    $expectedMigrations = DB::table('migrations')->count();
    DB::statement('CREATE DATABASE `'.$temporaryDatabase.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $connection = config('database.connections.'.config('database.default'));
    $connection['database'] = $temporaryDatabase;
    config(['database.connections.restore_check' => $connection]);
    DB::connection('restore_check')->unprepared($sql);
    if (DB::connection('restore_check')->table('migrations')->count() !== $expectedMigrations) {
        throw new RuntimeException('Restored schema metadata did not match.');
    }
    foreach (['tickets', 'branches', 'users', 'outbox_messages', 'counter_sessions'] as $table) {
        DB::connection('restore_check')->table($table)->count();
    }
    echo 'MySQL backup restored successfully into an isolated temporary database. Private backup: '.$backup['path'].PHP_EOL;
} finally {
    DB::purge('restore_check');
    if ($created) {
        DB::statement('DROP DATABASE `'.$temporaryDatabase.'`');
    }
}
