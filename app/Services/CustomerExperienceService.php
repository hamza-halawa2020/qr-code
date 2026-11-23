<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CustomerExperienceService
{
    public function isNear(Ticket $ticket): bool
    {
        if ($ticket->status->value !== 'waiting' || $ticket->appointment_at?->isFuture() || ! $ticket->branch->acceptsBookings() || $ticket->ahead() > 2) {
            return false;
        }

        return Counter::where('branch_id', $ticket->branch_id)->where('is_paused', false)->whereHas('user', fn ($q) => $q->where('is_active', true)->whereIn('role', ['admin', 'manager', 'agent'])->where(fn ($q) => $q->where('branch_id', $ticket->branch_id)->orWhere('role', 'admin'))->when($ticket->branch_service_id, fn ($q) => $q->whereHas('services', fn ($q) => $q->where('branch_services.id', $ticket->branch_service_id))))->exists();
    }

    public function rate(string $secret, array $input): void
    {
        $ticket = app(CustomerQueueService::class)->findTicket($secret);
        $data = Validator::make($input, ['rating' => 'required|integer|min:1|max:5', 'rating_comment' => 'nullable|string|max:1000'])->validate();
        DB::transaction(function () use ($ticket, $data) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if ($ticket->status->value !== 'completed') {
                throw ValidationException::withMessages(['rating' => __('queue.err_rating_not_completed')]);
            }
            if ($ticket->rated_at) {
                return;
            }
            $ticket->update(['rating' => $data['rating'], 'rating_comment' => $data['rating_comment'] ?? null, 'rated_at' => now()]);
        });
    }

    public function enqueueNear(Branch $branch): void
    {
        $branch->refresh();
        // Callers hold the branch lock. A ticket gets one persistent reminder.
        $tickets = Ticket::where('branch_id', $branch->id)->where('status', 'waiting')->whereNull('near_notified_at')
            ->where(fn ($q) => $q->whereNull('appointment_at')->orWhere('appointment_at', '<=', now()))
            ->whereDate('business_date', now($branch->timezone)->toDateString())
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('push_subscriptions')->whereColumn('ticket_id', 'tickets.id'))->get();
        foreach ($tickets as $ticket) {
            if ($this->isNear($ticket)) {
                $ticket->update(['near_notified_at' => now()]);
                OutboxMessage::create(['branch_id' => $branch->id, 'ticket_id' => $ticket->id, 'version' => $branch->version, 'type' => 'near_turn']);
            }
        }
    }
}
