<?php

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\Setting;
use App\Services\CloseBusinessDay;
use App\Services\CustomerExperienceService;
use App\Services\QueueRetentionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('queue:publish-pending', function () {
    if (! config('firebase.enabled')) {
        $this->warn('Firebase disabled; messages are retained.');

        return;
    }
    foreach (OutboxMessage::whereNull('processed_at')->distinct()->pluck('branch_id') as $branchId) {
        PublishBranch::dispatch($branchId);
    }
})->purpose('Retry pending Firebase outbox messages');

Artisan::command('queue:close-day', function () {
    $count = 0;
    foreach (Branch::all() as $branch) {
        $count += app(CloseBusinessDay::class)->run($branch);
    }
    $this->info("Expired {$count} waiting/called tickets.");
})->purpose('Expire unserved tickets after branch closing time');

Artisan::command('appointments:expire', function () {
    $count = \App\Models\Appointment::where('status', 'booked')->whereHas('slot', fn ($q) => $q->where('starts_at', '<', now()->subMinutes(30)))->update(['status' => 'expired']);
    $this->info("Expired {$count} missed appointments.");
})->purpose('Expire appointments that missed their arrival window');

Artisan::command('queue:near-reminders', function () {
    if (! config('firebase.enabled')) { return; }
    foreach (Branch::orderBy('id')->cursor() as $branch) {
        DB::transaction(function () use ($branch) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            app(CustomerExperienceService::class)->enqueueNear($branch);
            if (OutboxMessage::where('branch_id', $branch->id)->whereNull('processed_at')->exists()) { PublishBranch::dispatch($branch->id)->afterCommit(); }
        });
    }
})->purpose('Enqueue one approaching-turn reminder for subscribed customers');

Artisan::command('queue:prune {--dry-run : Show eligible record counts without deleting}', function () {
    $counts = app(QueueRetentionService::class)->prune((bool) $this->option('dry-run'));
    $this->info($this->option('dry-run') ? 'Dry run: no records were deleted.' : 'Retention cleanup completed.');
    foreach ($counts as $name => $count) {
        $this->line($name.': '.$count);
    }
})->purpose('Apply configured retention to ended tickets and old operational records');

// Background Scheduling:
// 1. Live Sync & Realtime Notifications: run every minute.
Schedule::command('queue:publish-pending')->everyMinute()->withoutOverlapping();
Schedule::command('queue:near-reminders')->everyMinute()->withoutOverlapping();

// 2. Operational maintenance:
Schedule::command('appointments:expire')->everyTenMinutes()->withoutOverlapping();
Schedule::command('queue:close-day')->everyFifteenMinutes()->withoutOverlapping();

$pruneTime = '03:00';
try {
    $configured = Setting::get('prune_time');
    if ($configured && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $configured)) {
        $pruneTime = $configured;
    }
} catch (\Throwable) {
    $pruneTime = '03:00';
}
Schedule::command('queue:prune')->dailyAt($pruneTime)->timezone('Africa/Cairo')->withoutOverlapping();
