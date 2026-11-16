<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Jobs\PublishBranch;
use App\Models\{Branch, Counter, OutboxMessage, Ticket, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QueueService
{
    public function book(Branch $branch, string $secret, string $bookingKey): Ticket
    {
        return DB::transaction(function () use ($branch, $secret, $bookingKey) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            if ($existing = Ticket::where('booking_key', hash('sha256', $bookingKey))->first()) {
                abort_unless($existing->branch_id === $branch->id && hash_equals($existing->token_hash, hash('sha256', $secret)), 403);
                return $existing;
            }
            $this->require($branch->acceptsBookings(), 'الحجز متوقف حاليًا. راجع مواعيد عمل الفرع.');
            $date = now($branch->timezone)->toDateString();
            DB::table('daily_sequences')->insertOrIgnore(['branch_id' => $branch->id, 'business_date' => $date, 'last_number' => 0]);
            $sequence = DB::table('daily_sequences')->where('branch_id', $branch->id)->where('business_date', $date)->lockForUpdate()->first();
            DB::table('daily_sequences')->where('id', $sequence->id)->increment('last_number');
            $ticket = Ticket::create([
                'public_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $secret),
                'booking_key' => hash('sha256', $bookingKey), 'branch_id' => $branch->id,
                'business_date' => $date, 'number' => $sequence->last_number + 1,
                'status' => TicketStatus::Waiting,
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
            $this->require(! $counter->is_paused, 'الشباك في استراحة. استأنف العمل أولًا.');
            $this->require(! $counter->currentTicket(), 'أنهِ معالجة العميل الحالي قبل استدعاء التالي.');
            $ticket = Ticket::where('branch_id', $branch->id)->whereDate('business_date', now($branch->timezone)->toDateString())
                ->where('status', 'waiting')->orderBy('number')->lockForUpdate()->first();
            if (! $ticket) { return null; }
            $ticket->update(['status' => TicketStatus::Called, 'counter_id' => $counter->id, 'user_id' => $user->id, 'called_at' => now()]);
            $this->record($branch, $ticket, 'called', TicketStatus::Waiting, $user);
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
                'cancel' => $from === TicketStatus::Waiting,
            };
            $this->require($allowed, 'لا يمكن تنفيذ الإجراء على حالة التذكرة الحالية.');
            $values = ['status' => $target];
            if ($action === 'start') { $values['started_at'] = now(); }
            if (in_array($action, ['complete', 'absent', 'cancel'])) { $values['finished_at'] = now(); }
            if ($action === 'recall') { $values['called_at'] = now(); }
            $ticket->update($values);
            $this->record($branch, $ticket, $action === 'recall' ? 'recalled' : $action, $from, $user);
            return $ticket;
        }, 5);
    }

    public function pause(Counter $counter, User $user): void
    {
        DB::transaction(function () use ($counter, $user) {
            $branch = Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $counter = Counter::whereKey($counter->id)->lockForUpdate()->firstOrFail();
            $this->authorize($counter, $user);
            $counter->update(['is_paused' => ! $counter->is_paused]);
            $branch->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'counter_changed']);
            if (config('firebase.enabled')) { PublishBranch::dispatch($branch->id)->afterCommit(); }
        });
    }

    private function authorize(Counter $counter, User $user): void
    {
        abort_unless($counter->user_id === $user->id && ($user->role === 'admin' || $user->branch_id === $counter->branch_id), 403);
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) { throw ValidationException::withMessages(['queue' => $message]); }
    }

    private function record(Branch $branch, Ticket $ticket, string $type, ?TicketStatus $from, ?User $user): void
    {
        DB::table('ticket_events')->insert(['ticket_id' => $ticket->id, 'user_id' => $user?->id, 'type' => $type, 'from_status' => $from?->value, 'to_status' => $ticket->status->value, 'created_at' => now()]);
        $branch->increment('version');
        OutboxMessage::create(['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'version' => $branch->version, 'type' => $type]);
        if (config('firebase.enabled')) { PublishBranch::dispatch($branch->id)->afterCommit(); }
    }
}
