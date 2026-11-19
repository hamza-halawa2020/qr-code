<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class CloseBusinessDay
{
    public function run(Branch $branch): int
    {
        return DB::transaction(function () use ($branch) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $local = now($branch->timezone);
            $hours = $branch->hoursFor($local);
            $cutoff = $hours['is_closed'] ? '00:00' : $hours['closes_at'];
            $query = Ticket::where('branch_id', $branch->id)->whereIn('status', ['waiting', 'called', 'deferred'])->where(function ($q) use ($local, $cutoff) {
                $q->whereDate('business_date', '<', $local->toDateString());
                if ($local->format('H:i') >= $cutoff) {
                    $q->orWhereDate('business_date', $local->toDateString());
                }
            });
            $tickets = $query->lockForUpdate()->get();
            foreach ($tickets as $ticket) {
                $from = $ticket->status->value;
                $ticket->update(['status' => 'expired', 'finished_at' => now()]);
                DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'type' => 'day_closed', 'from_status' => $from, 'to_status' => 'expired', 'created_at' => now()]);
                $branch->increment('version');
                OutboxMessage::create(['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'version' => $branch->version, 'type' => 'expired']);
            }
            // Already started service must be finished by its employee.
            if ($tickets->isNotEmpty() && config('firebase.enabled')) {
                PublishBranch::dispatch($branch->id)->afterCommit();
            }

            app(CounterSessionService::class)->closeBusinessDay($branch);

            return $tickets->count();
        });
    }
}
