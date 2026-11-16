<?php

namespace App\Jobs;

use App\Models\{Branch, OutboxMessage, Ticket};
use App\Services\{FirebaseGateway, QueueSnapshot};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PublishBranch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $timeout = 120;
    public function __construct(public int $branchId) {}
    public function backoff(): array { return [5, 15, 60, 120]; }

    public function handle(FirebaseGateway $firebase, QueueSnapshot $snapshot): void
    {
        if (! config('firebase.enabled')) { return; }
        // All publishers use the same branch lock as queue mutations, so an old
        // job can only publish the latest committed state, never an older copy.
        DB::transaction(function () use ($firebase, $snapshot) {
            $branch = Branch::whereKey($this->branchId)->lockForUpdate()->firstOrFail();
            $messages = OutboxMessage::where('branch_id', $branch->id)->whereNull('processed_at')->orderBy('id')->get();
            if ($messages->isEmpty()) { return; }
            $documents = [];
            foreach ($branch->counters as $counter) {
                $documents['counters/'.$counter->id] = $snapshot->counter($counter);
            }
            $tickets = Ticket::where('branch_id', $branch->id)->where(function ($query) use ($messages) {
                $query->whereIn('status', ['waiting', 'called', 'serving'])->orWhereIn('id', $messages->pluck('ticket_id')->filter());
            })->get();
            foreach ($tickets as $ticket) {
                $documents['tickets/'.$ticket->public_id] = $snapshot->ticket($ticket);
            }
            $firebase->publish($documents);
            foreach ($messages->whereIn('type', ['called', 'recalled']) as $message) {
                $ticket = $tickets->firstWhere('id', $message->ticket_id);
                // Do not deliver obsolete calls after service has started or a
                // newer recall has replaced the event.
                $latest = $messages->where('ticket_id', $message->ticket_id)->last();
                if (! $ticket || $ticket->status->value !== 'called' || $latest->id !== $message->id) { continue; }
                foreach (DB::table('push_subscriptions')->where('ticket_id', $ticket->id)->get() as $subscription) {
                    if (! $firebase->notify($subscription->token, (string) $message->id, $snapshot->ticket($ticket))) {
                        DB::table('push_subscriptions')->where('id', $subscription->id)->delete();
                    }
                }
            }
            OutboxMessage::whereIn('id', $messages->pluck('id'))->update(['processed_at' => now()]);
        });
    }
}
