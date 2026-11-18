<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AutomaticQueueService
{
    public function expire(int $ticketId): bool
    {
        $ticket = Ticket::find($ticketId);
        if (! $ticket) {
            return false;
        }

        return DB::transaction(function () use ($ticket) {
            $branch = Branch::whereKey($ticket->branch_id)->lockForUpdate()->firstOrFail();
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
            if (! $ticket || ! $branch->automatic_queue || ! $branch->acceptsBookings()
                || $ticket->status->value !== 'called' || ! $ticket->called_at
                || $ticket->business_date->toDateString() !== now($branch->timezone)->toDateString()
                || $ticket->callDeadline()->isFuture()) {
                return false;
            }
            $counter = Counter::whereKey($ticket->counter_id)->lockForUpdate()->first();
            $user = User::find($ticket->user_id);
            if (! $counter || $counter->is_paused || ! $user?->is_active
                || ! in_array($user->role, ['admin', 'manager', 'agent'], true)
                || $counter->user_id !== $user->id
                || ($user->role !== 'admin' && $user->branch_id !== $branch->id)) {
                return false;
            }
            // The transition and subsequent call share the same branch lock.
            app(QueueService::class)->transition($ticket, 'absent', $user);

            return true;
        }, 5);
    }

    public function sweep(): int
    {
        $count = 0;
        Ticket::where('status', 'called')->whereHas('branch', fn ($query) => $query->where('automatic_queue', true))
            ->where(fn ($query) => $query->where('call_expires_at', '<=', now())->orWhereNull('call_expires_at'))
            ->orderBy('id')->chunkById(100, function ($tickets) use (&$count) {
                foreach ($tickets as $ticket) {
                    $count += (int) $this->expire($ticket->id);
                }
            });

        return $count;
    }
}
