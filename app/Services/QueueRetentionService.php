<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\CounterSession;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class QueueRetentionService
{
    public function prune(bool $dryRun = false): array
    {
        $days = config('queue-data.retention_days');
        if (! is_int($days) || $days < 1) {
            throw new RuntimeException('QUEUE_RETENTION_DAYS must be a positive number of days.');
        }
        $cutoff = now()->subDays($days);
        $counts = ['tickets' => 0, 'sessions' => 0, 'commands' => 0, 'publishedEvents' => 0, 'failedJobs' => 0];
        foreach (Branch::orderBy('id')->cursor() as $branch) {
            do {
                $deleted = DB::transaction(function () use ($branch, $cutoff, $dryRun) {
                    $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
                    $query = $this->eligibleTickets($cutoff)->where('branch_id', $branch->id);
                    if ($dryRun) {
                        return $query->count();
                    }
                    $tickets = $query->limit(500)->lockForUpdate()->get();
                    foreach ($tickets as $ticket) {
                        // Keep the public ID until the remote deletion is confirmed.
                        $branch->increment('version');
                        OutboxMessage::create(['branch_id' => $branch->id, 'ticket_public_id' => $ticket->public_id, 'version' => $branch->version, 'type' => 'ticket_deleted']);
                        $ticket->delete();
                    }
                    if ($tickets->isNotEmpty() && config('firebase.enabled')) {
                        PublishBranch::dispatch($branch->id)->afterCommit();
                    }

                    return $tickets->count();
                }, 5);
                $counts['tickets'] += $deleted;
            } while (! $dryRun && $deleted === 500);
        }
        $queries = [
            'appointmentSlots' => \App\Models\AppointmentSlot::where('starts_at', '<', $cutoff),
            'sessions' => CounterSession::whereNotNull('ended_at')->where('ended_at', '<', $cutoff),
            'commands' => DB::table('reception_commands')->where('created_at', '<', $cutoff)->where(function ($query) use ($cutoff) {
                $query->whereNull('result_ticket_id')->orWhereIn('result_ticket_id', $this->eligibleTickets($cutoff)->select('tickets.id'));
            }),
            'publishedEvents' => OutboxMessage::whereNotNull('processed_at')->where('created_at', '<', $cutoff),
            'failedJobs' => DB::table('failed_jobs')->where('failed_at', '<', $cutoff),
        ];
        foreach ($queries as $key => $query) {
            $counts[$key] = $dryRun ? $query->count() : $query->delete();
        }

        return $counts;
    }

    private function eligibleTickets(CarbonInterface $cutoff): Builder
    {
        return Ticket::whereIn('status', ['completed', 'cancelled', 'no_show', 'expired'])->where('finished_at', '<', $cutoff)
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('outbox_messages')->whereColumn('outbox_messages.ticket_id', 'tickets.id')->whereNull('outbox_messages.processed_at');
            });
    }
}
