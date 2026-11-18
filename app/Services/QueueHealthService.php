<?php

namespace App\Services;

use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class QueueHealthService
{
    public function report(User $user): array
    {
        abort_unless($user->is_active && $user->role === 'admin', 403);
        $web = config('firebase.web');
        $required = ['apiKey', 'authDomain', 'projectId', 'messagingSenderId', 'appId'];
        $missing = array_values(array_filter($required, fn ($key) => blank($web[$key] ?? null)));
        if (blank(config('firebase.vapid_key'))) {
            $missing[] = 'VAPID public key';
        }
        if (! is_file(config('firebase.credentials'))) {
            $missing[] = 'Service account file';
        }
        $backupFiles = array_merge(glob(config('backups.directory').'/*.sql') ?: [], glob(config('backups.directory').'/*.sqlite') ?: []);
        $backupTimes = array_map('filemtime', $backupFiles);

        return [
            'queuedJobs' => DB::table('jobs')->count(), 'failedJobs' => DB::table('failed_jobs')->count(),
            'pendingEvents' => OutboxMessage::whereNull('processed_at')->count(),
            'oldestPending' => OutboxMessage::whereNull('processed_at')->min('created_at'),
            'lastPublished' => OutboxMessage::max('processed_at'),
            'schedulerHeartbeat' => Cache::get('queue:scheduler-heartbeat'),
            'workerHeartbeat' => Cache::get('queue:worker-heartbeat'),
            'firebaseEnabled' => config('firebase.enabled'), 'missingFirebaseSettings' => $missing,
            'retentionDays' => config('queue-data.retention_days'), 'backupCount' => count($backupFiles),
            'lastBackup' => $backupTimes ? max($backupTimes) : null, 'scheduledBackupsEnabled' => config('backups.enabled'),
        ];
    }
}
