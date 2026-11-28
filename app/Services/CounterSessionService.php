<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CounterSessionService
{
    public function start(Counter $counter, User $user): CounterSession
    {
        return DB::transaction(function () use ($counter, $user) {
            $branch = Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $counter = Counter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $counter->user_id === $user->id, 403);
            $date = now($branch->timezone)->toDateString();
            $session = $this->active($counter);
            if ($session && $session->user_id === $user->id && ($session->business_date->toDateString() === $date || $counter->currentTicket())) {
                return $session;
            }
            if ($session) {
                $this->closeForCounter($counter, 'day_changed');
            }

            return CounterSession::create(['branch_id' => $branch->id, 'counter_id' => $counter->id, 'user_id' => $user->id, 'active_counter_id' => $counter->id, 'active_user_id' => $user->id, 'business_date' => $date, 'started_at' => now()]);
        }, 5);
    }

    public function finish(Counter $counter, User $user): void
    {
        DB::transaction(function () use ($counter, $user) {
            Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $counter = Counter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->is_active && $counter->user_id === $user->id, 403);
            if ($counter->currentTicket()) {
                throw ValidationException::withMessages(['queue' => __('queue.err_shift_has_ticket')]);
            }
            $this->closeForCounter($counter, 'employee_finished');
            if (! $counter->is_paused) {
                app(QueueService::class)->pause($counter, $user);
            }
        }, 5);
    }

    public function active(Counter $counter): ?CounterSession
    {
        return CounterSession::where('counter_id', $counter->id)->whereNull('ended_at')->first();
    }

    // Callers hold the branch lock when closing assignments or accounts.
    public function closeForCounter(Counter $counter, string $reason): void
    {
        CounterSession::where('counter_id', $counter->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => $reason, 'active_counter_id' => null, 'active_user_id' => null]);
    }

    public function closeBusinessDay(Branch $branch): void
    {
        $local = now($branch->timezone);
        $hours = $branch->hoursFor($local);
        $cutoff = $hours['is_closed'] ? '00:00' : $hours['closes_at'];
        $sessions = CounterSession::with('counter')->where('branch_id', $branch->id)->whereNull('ended_at')->get();
        foreach ($sessions as $session) {
            $closed = $session->business_date->toDateString() < $local->toDateString() || $local->format('H:i') >= $cutoff;
            if ($closed && ! $session->counter->currentTicket()) {
                $this->closeForCounter($session->counter, 'day_closed');
            }
        }
    }
}
