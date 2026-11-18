<?php

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\QueueService;
use App\Services\ReceptionDeskService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['firebase.enabled' => false]);

if (! app()->environment('local') || DB::getDriverName() !== 'mysql') {
    fwrite(STDERR, "This integration check requires a local MySQL connection.\n");
    exit(1);
}

if (($argv[1] ?? '') === '--worker') {
    try {
        $mode = $argv[2];
        if ($mode === 'appointment_book') {
            $session = new \Illuminate\Session\Store('concurrency', new \Illuminate\Session\ArraySessionHandler(120)); $session->start();
            $secret = app(\App\Services\AppointmentService::class)->book(Branch::findOrFail($argv[3]), $session, ['slot_id'=>(int)$argv[4], 'customer_name'=>'Test customer', 'customer_phone'=>'01012345678']);
            echo json_encode(['secret'=>$secret]); exit;
        }
        if ($mode === 'appointment_checkin') {
            $session = new \Illuminate\Session\Store('concurrency', new \Illuminate\Session\ArraySessionHandler(120)); $session->start();
            app(\App\Services\AppointmentService::class)->checkIn($argv[3], $session);
            echo json_encode(['id'=>app(\App\Services\AppointmentService::class)->find($argv[3])->ticket_id]); exit;
        }
        if ($mode === 'expire') {
            echo json_encode(['advanced' => app(\App\Services\AutomaticQueueService::class)->expire((int) $argv[3])]);
            exit;
        }
        if ($mode === 'book') {
            $secret = Str::random(64);
            $ticket = app(QueueService::class)->book(Branch::findOrFail($argv[3]), $secret, $secret, ['service_id' => (int) $argv[4]]);
        } else {
            $counter = Counter::findOrFail($argv[3]);
            $ticket = in_array($mode, ['command_next', 'command_complete'], true)
                ? app(ReceptionDeskService::class)->command($counter->user, $argv[4], $mode === 'command_next' ? 'next' : 'complete', $mode === 'command_complete' ? (int) $argv[5] : null)
                : app(QueueService::class)->next($counter, $counter->user);
        }
        echo json_encode(['number' => $ticket?->number, 'id' => $ticket?->id]);
    } catch (ValidationException) {
        echo json_encode(['rejected' => true]);
    }
    exit;
}

function parallelChecks(array $tasks): array
{
    $workers = [];
    foreach ($tasks as $task) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'auto_prepend_file=', __FILE__, '--worker', ...$task], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
        if (! is_resource($process)) {
            throw new RuntimeException('Cannot start concurrency worker.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes];
    }
    $results = [];
    foreach ($workers as [$process, $pipes]) {
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException('Worker failed: '.$errors);
        }
        $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    return $results;
}

$branch = null;
$users = [];
try {
    $branch = Branch::create(['name' => '__concurrency_check_'.Str::uuid(), 'opens_at' => '00:00', 'closes_at' => '23:59']);
    $supported = $branch->services()->create(['name' => 'Supported']);
    $unsupported = $branch->services()->create(['name' => 'Unsupported']);
    $skipped = app(QueueService::class)->book($branch, $secret = Str::random(64), $secret, ['service_id' => $unsupported->id]);
    $counters = [];
    foreach ([1, 2, 3] as $number) {
        $user = User::create(['name' => 'Temporary concurrency worker', 'email' => Str::uuid().'@example.test', 'password' => Str::random(40), 'role' => 'agent', 'branch_id' => $branch->id]);
        $users[] = $user->id;
        $user->services()->sync([$supported->id]);
        $counters[] = Counter::create(['branch_id' => $branch->id, 'name' => 'Test '.$number, 'number' => $number, 'user_id' => $user->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }
    $bookings = parallelChecks(array_fill(0, 4, ['book', (string) $branch->id, (string) $supported->id]));
    $numbers = array_column($bookings, 'number');
    sort($numbers);
    if ($numbers !== [2, 3, 4, 5]) {
        throw new RuntimeException('Concurrent booking sequence was not unique and ordered.');
    }
    $calls = parallelChecks(array_map(fn ($counter) => ['next', (string) $counter->id], array_slice($counters, 0, 2)));
    $called = array_column($calls, 'number');
    sort($called);
    if ($called !== [2, 3]) {
        throw new RuntimeException('Employees did not receive distinct earliest customers.');
    }
    $duplicates = parallelChecks(array_fill(0, 2, ['next', (string) $counters[0]->id]));
    if (count(array_filter($duplicates, fn ($result) => $result['rejected'] ?? false)) !== 2) {
        throw new RuntimeException('Repeated next replaced an active ticket.');
    }
    if (Ticket::where('branch_id', $branch->id)->where('status', 'waiting')->count() !== 3 || $skipped->fresh()->status->value !== 'waiting') {
        throw new RuntimeException('Remaining queue changed unexpectedly.');
    }
    $key = (string) Str::uuid();
    $replays = parallelChecks(array_fill(0, 2, ['command_next', (string) $counters[2]->id, $key]));
    if (count(array_unique(array_column($replays, 'id'))) !== 1 || $replays[0]['number'] !== 4) {
        throw new RuntimeException('Concurrent command replay did not return the same customer.');
    }
    $branch->update(['automatic_queue' => true, 'no_show_after_seconds' => 30]);
    $expired = Ticket::findOrFail($replays[0]['id']);
    $expired->update(['call_expires_at' => now()->subSecond()]);
    $timeouts = parallelChecks(array_fill(0, 2, ['expire', (string) $expired->id]));
    if (count(array_filter($timeouts, fn ($result) => $result['advanced'])) > 1 || $counters[2]->currentTicket()?->number !== 5
        || DB::table('ticket_events')->where('ticket_id', $expired->id)->where('type', 'absent')->count() !== 1) {
        throw new RuntimeException('Concurrent timeouts advanced the queue more than once.');
    }
    $current = $counters[2]->currentTicket();
    app(QueueService::class)->transition($current, 'start', $counters[2]->user);
    foreach ([1, 2] as $extra) {
        app(QueueService::class)->book($branch, $secret = Str::random(64), $secret, ['service_id' => $supported->id]);
    }
    $key = (string) Str::uuid();
    parallelChecks(array_fill(0, 2, ['command_complete', (string) $counters[2]->id, $key, (string) $current->id]));
    if ($counters[2]->currentTicket()?->number !== 6 || Ticket::where('branch_id', $branch->id)->where('number', 7)->firstOrFail()->status->value !== 'waiting') {
        throw new RuntimeException('Repeated completion advanced the queue more than once.');
    }
    $branch->update(['automatic_queue'=>false]);
    $slot = \App\Models\AppointmentSlot::create(['branch_id'=>$branch->id,'branch_service_id'=>$supported->id,'starts_at'=>now()->addMinutes(10),'capacity'=>1]);
    $reservations = parallelChecks(array_fill(0,2,['appointment_book',(string)$branch->id,(string)$slot->id]));
    $accepted = array_values(array_filter($reservations,fn($result)=>isset($result['secret'])));
    if(count($accepted)!==1 || $slot->appointments()->count()!==1) { throw new RuntimeException('Concurrent appointment capacity exceeded.'); }
    $slot->update(['starts_at'=>now()]);
    $arrivals=parallelChecks(array_fill(0,2,['appointment_checkin',$accepted[0]['secret']]));
    if(count(array_unique(array_column($arrivals,'id')))!==1) { throw new RuntimeException('Concurrent check-in created duplicate tickets.'); }
    $active=$counters[0]->currentTicket();
    app(QueueService::class)->transition($active,'start',$counters[0]->user);
    app(QueueService::class)->transition($active,'complete',$counters[0]->user);
    if(app(QueueService::class)->next($counters[0],$counters[0]->user)?->id!==$arrivals[0]['id']) { throw new RuntimeException('Due appointment did not receive priority at first suitable free counter.'); }
    $retry=app(QueueService::class)->book($branch,$secret=Str::random(64),$secret,['service_id'=>$supported->id]);
    $retry->update(['status'=>'deferred','retry_limit'=>1,'retry_attempts'=>0,'deferred_at'=>now()]);
    $branch->update(['automatic_queue'=>true]);
    $tasks=[];
    foreach([$counters[1],$counters[2]] as $counter){
        $active=$counter->currentTicket(); app(QueueService::class)->transition($active,'start',$counter->user);
        $tasks[]=['command_complete',(string)$counter->id,(string)Str::uuid(),(string)$active->id];
    }
    parallelChecks($tasks);
    if(DB::table('ticket_events')->where('ticket_id',$retry->id)->where('type','retry_ready')->count()!==1
        || DB::table('ticket_events')->where('ticket_id',$retry->id)->where('type','retry_called')->count()!==1
        || (int)$retry->fresh()->retry_attempts!==1){throw new RuntimeException('Concurrent completions duplicated a missed-turn retry.');}
    echo "MySQL concurrency passed: matching calls, idempotent timeouts/completions, appointment capacity/priority, and a single retry call under concurrent completions.\n";
} finally {
    // Only IDs created by this check are removed; existing branch data is untouched.
    if ($branch) {
        DB::transaction(function () use ($branch, $users) {
            DB::table('outbox_messages')->where('branch_id', $branch->id)->delete();
            Ticket::where('branch_id', $branch->id)->delete();
            Counter::where('branch_id', $branch->id)->delete();
            DB::table('daily_sequences')->where('branch_id', $branch->id)->delete();
            User::whereIn('id', $users)->delete();
            $branch->delete();
        });
    }
}
