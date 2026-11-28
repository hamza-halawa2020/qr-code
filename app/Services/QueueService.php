<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QueueService
{
    public function book(Branch $branch, string $secret, string $bookingKey, array $details = [], bool $honorReservedService = false): Ticket
    {
        return DB::transaction(function () use ($branch, $secret, $bookingKey, $details, $honorReservedService) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($existing = Ticket::where('booking_key', hash('sha256', $bookingKey))->first()) {
                abort_unless($existing->branch_id === $branch->id && hash_equals($existing->token_hash, hash('sha256', $secret)), 403);

                return $existing;
            }
            $this->require($branch->acceptsBookings(), __('queue.err_booking_closed'));
            $bookingDetails = $details ? app(BookingDetailsService::class)->validate($branch, $details, false, $honorReservedService) : [];
            $date = now($branch->timezone)->toDateString();
            DB::table('daily_sequences')->insertOrIgnore(['branch_id' => $branch->id, 'business_date' => $date, 'last_number' => 0]);
            $sequence = DB::table('daily_sequences')->where('branch_id', $branch->id)->where('business_date', $date)->lockForUpdate()->first();
            DB::table('daily_sequences')->where('id', $sequence->id)->increment('last_number');
            $ticket = Ticket::create([
                'public_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $secret),
                'booking_key' => hash('sha256', $bookingKey), 'branch_id' => $branch->id,
                'business_date' => $date, 'number' => $sequence->last_number + 1,
                'status' => TicketStatus::Waiting,
                ...$bookingDetails,
            ]);
            $this->record($branch, $ticket, 'booked', null, null);

            return $ticket;
        }, 5);
    }

    public function next(Counter $counter, User $user): ?Ticket
    {
        return DB::transaction(function () use ($counter, $user) {
            $branch = Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $counter = Counter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
            $this->authorize($counter, $user);
            $this->require(! $counter->is_paused, __('queue.err_counter_paused'));
            $this->require(! $counter->currentTicket(), __('queue.err_counter_has_ticket'));
            $waiting = Ticket::where('branch_id', $branch->id)->whereDate('business_date', now($branch->timezone)->toDateString())->where('status', 'waiting');
            $ticket = app(QueueOrderService::class)->order(app(EmployeeServiceService::class)->eligible($waiting, $user, $branch->id))->lockForUpdate()->first();
            if (! $ticket) {
                return null;
            }
            $retry = $ticket->retry_ready_at !== null;
            $ticket->update(['status' => TicketStatus::Called, 'counter_id' => $counter->id, 'user_id' => $user->id, 'called_at' => now(), 'call_expires_at' => now()->addSeconds($branch->no_show_after_seconds), 'retry_ready_at' => null, 'retry_attempts' => $ticket->retry_attempts + ($retry ? 1 : 0)]);
            $this->record($branch, $ticket, $retry ? 'retry_called' : 'called', TicketStatus::Waiting, $user);

            return $ticket;
        }, 5);
    }

    public function transition(Ticket $ticket, string $action, ?User $user = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $action, $user) {
            $branch = Branch::whereKey($ticket->branch_id)->lockForUpdate()->firstOrFail();
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $ticket->status;
            if ($action !== 'cancel') {
                abort_unless($user && $ticket->counter, 403);
                $this->authorize($ticket->counter, $user);
            }
            $target = match ($action) {
                'start' => TicketStatus::Serving, 'complete' => TicketStatus::Completed,
                'absent' => TicketStatus::NoShow, 'cancel' => TicketStatus::Cancelled,
                'recall' => TicketStatus::Called, default => abort(422),
            };
            $allowed = match ($action) {
                'start', 'absent', 'recall' => $from === TicketStatus::Called,
                'complete' => $from === TicketStatus::Serving,
                'cancel' => in_array($from, [TicketStatus::Waiting, TicketStatus::Deferred], true),
            };
            $this->require($allowed, __('queue.err_transition_not_allowed'));
            if ($action === 'absent') {
                $this->require($ticket->called_at && ! $ticket->callDeadline()->isFuture(), __('queue.err_absent_timer_active'));
                $limit = $ticket->retry_limit ?? $branch->missed_turn_retries;
                if ($ticket->retry_attempts < $limit) {
                    $target = TicketStatus::Deferred;
                }
            }
            $values = ['status' => $target];
            if ($action === 'start') {
                $values['started_at'] = now();
            }
            if (in_array($action, ['complete', 'absent', 'cancel']) && $target !== TicketStatus::Deferred) {
                $values['finished_at'] = now();
            }
            if ($action === 'absent') {
                $values['retry_limit'] = $limit;
            }
            if ($target === TicketStatus::Deferred) {
                $values += ['counter_id' => null, 'user_id' => null, 'deferred_at' => now(), 'retry_ready_at' => null, 'finished_at' => null, 'appointment_at' => null];
            }
            $counter = $ticket->counter;
            if ($action === 'recall') {
                $values['called_at'] = now();
                $values['call_expires_at'] = now()->addSeconds($branch->no_show_after_seconds);
            }
            $ticket->update($values);
            $this->record($branch, $ticket, $target === TicketStatus::Deferred ? 'deferred' : ($action === 'recall' ? 'recalled' : $action), $from, $user);
            if ($action === 'complete') {
                $deferred = Ticket::where('branch_id', $branch->id)->whereDate('business_date', now($branch->timezone)->toDateString())
                    ->where('branch_service_id', $ticket->branch_service_id)->where('status', 'deferred')->orderBy('deferred_at')->orderBy('number')->lockForUpdate()->first();
                if ($deferred) {
                    $deferred->update(['status' => TicketStatus::Waiting, 'retry_ready_at' => now()]);
                    $this->record($branch, $deferred, 'retry_ready', TicketStatus::Deferred, $user);
                }
            }
            if (in_array($action, ['complete', 'absent'], true) && $branch->automatic_queue && $branch->acceptsBookings()
                && $counter && ! $counter->fresh()->is_paused) {
                $this->next($counter, $user);
            }

            return $ticket;
        }, 5);
    }

    /**
     * Return a called or serving ticket back to the waiting queue.
     */
    public function requeue(Ticket $ticket, User $user, ?int $newBranchServiceId = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $user, $newBranchServiceId) {
            $branch = Branch::whereKey($ticket->branch_id)->lockForUpdate()->firstOrFail();
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $from = $ticket->status;

            $this->require(
                in_array($from, [TicketStatus::Called, TicketStatus::Serving], true),
                __('queue.err_requeue_status')
            );

            if ($newBranchServiceId !== null) {
                // Validate the new service belongs to this branch and is active.
                $exists = \App\Models\BranchService::where('id', $newBranchServiceId)
                    ->where('branch_id', $branch->id)
                    ->where('is_active', true)
                    ->exists();
                $this->require($exists, __('queue.err_requeue_service_invalid'));
            }

            $values = [
                'status'       => TicketStatus::Waiting,
                'counter_id'   => null,
                'user_id'      => null,
                'called_at'    => null,
                'call_expires_at' => null,
                'started_at'   => null,
                'finished_at'  => null,
                'deferred_at'  => null,
                'retry_ready_at' => null,
            ];

            if ($newBranchServiceId !== null) {
                $service = \App\Models\BranchService::with('service')->find($newBranchServiceId);
                $values['branch_service_id'] = $newBranchServiceId;
                $values['service_name']      = $service?->service?->name ?? $ticket->service_name;
            }

            $ticket->update($values);
            $this->record($branch, $ticket, 'requeued', $from, $user);

            return $ticket;
        }, 5);
    }

    public function pause(Counter $counter, User $user): void    {
        DB::transaction(function () use ($counter, $user) {
            $branch = Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $counter = Counter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
            $this->authorize($counter, $user);
            $counter->update(['is_paused' => ! $counter->is_paused]);
            $branch->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'counter_changed']);
            if (config('firebase.enabled')) {
                PublishBranch::dispatch($branch->id)->afterCommit();
            }
        });
    }

    private function authorize(Counter $counter, User $user): void
    {
        abort_unless($user->is_active, 403);
        abort_unless($counter->user_id === $user->id && ($user->role === 'admin' || $user->branch_id === $counter->branch_id), 403);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['queue' => $message]);
        }
    }

    private function record(Branch $branch, Ticket $ticket, string $type, ?TicketStatus $from, ?User $user): void
    {
        DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'user_id' => $user?->id, 'type' => $type, 'from_status' => $from?->value, 'to_status' => $ticket->status->value, 'created_at' => now()]);
        $branch->increment('version');
        OutboxMessage::create(['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'version' => $branch->version, 'type' => $type]);
        app(CustomerExperienceService::class)->enqueueNear($branch);
        if (config('firebase.enabled')) {
            PublishBranch::dispatch($branch->id)->afterCommit();
        }
    }
}
