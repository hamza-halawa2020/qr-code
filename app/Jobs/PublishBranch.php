<?php

namespace App\Jobs;

use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use App\Services\BranchBoardService;
use App\Services\CustomerExperienceService;
use App\Services\FirebaseGateway;
use App\Services\QueueSnapshot;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class PublishBranch implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public function __construct(public int $branchId) {}

    public function backoff(): array
    {
        return [5, 15, 60, 120];
    }

    public function handle(FirebaseGateway $firebase, QueueSnapshot $snapshot): void
    {
        if (! config('firebase.enabled')) {
            return;
        }
        // All publishers use the same branch lock as queue mutations, so an old
        // job can only publish the latest committed state, never an older copy.
        DB::transaction(function () use ($firebase, $snapshot) {
            $branch = Branch::whereKey($this->branchId)->lockForUpdate()->firstOrFail();
            $messages = OutboxMessage::where('branch_id', $branch->id)->whereNull('processed_at')->orderBy('id')->get();
            if ($messages->isEmpty()) {
                return;
            }
            $documents = [];
            if ($branch->board_token_hash) {
                $documents['branch_displays/'.$branch->id] = app(BranchBoardService::class)->snapshot($branch);
            }
            foreach ($branch->counters as $counter) {
                $documents['counters/'.$counter->id] = $snapshot->counter($counter);
            }
            $tickets = Ticket::where('branch_id', $branch->id)->where(function ($query) use ($messages) {
                $query->whereIn('status', ['waiting', 'called', 'serving', 'deferred'])->orWhereIn('id', $messages->pluck('ticket_id')->filter());
            })->get();
            foreach ($tickets as $ticket) {
                $documents['tickets/'.$ticket->public_id] = $snapshot->ticket($ticket);
            }
            $firebase->publish($documents);
            $deletions = $messages->where('type', 'ticket_deleted')->pluck('ticket_public_id')->filter()->unique()->map(fn ($id) => 'tickets/'.$id)->values()->all();
            if ($deletions !== []) {
                $firebase->deleteDocuments($deletions);
            }
            foreach ($messages->whereIn('type', ['called', 'recalled', 'retry_called', 'near_turn']) as $message) {
                $ticket = $tickets->firstWhere('id', $message->ticket_id);
                // Do not deliver obsolete calls after service has started or a
                // newer recall has replaced the event.
                $latest = $messages->where('ticket_id', $message->ticket_id)->last();
                $near = $message->type === 'near_turn';
                if (! $ticket || ($near ? ! app(CustomerExperienceService::class)->isNear($ticket) : ($ticket->status->value !== 'called' || $latest->id !== $message->id))) {
                    if ($near && $ticket?->status->value === 'waiting') {
                        $ticket->update(['near_notified_at' => null]);
                    }

                    continue;
                }
                foreach (DB::table('push_subscriptions')->where('ticket_id', $ticket->id)->get() as $subscription) {
                    if (! $firebase->notify($subscription->token, (string) $message->id, $snapshot->ticket($ticket) + ['notification_kind' => $near ? 'near_turn' : 'called'])) {
                        DB::table('push_subscriptions')->where('id', $subscription->id)->delete();
                    }
                }
            }
            OutboxMessage::whereIn('id', $messages->pluck('id'))->update(['processed_at' => now()]);
        });
    }
}
