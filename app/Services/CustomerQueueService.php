<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Ticket;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class CustomerQueueService
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly QueueSnapshot $snapshot,
    ) {}

    public function branches(): Collection
    {
        return Branch::orderBy('name')->get();
    }

    public function branchData(Branch $branch, Session $session): array
    {
        $secret = $session->get('ticket.'.$branch->id);
        $active = $secret ? Ticket::where('token_hash', hash('sha256', $secret))->first() : null;

        $services = $branch->services()->where('is_active', true)->orderBy('name')->get();

        return compact('branch', 'active', 'secret', 'services');
    }

    public function book(Branch $branch, Session $session, array $input = []): string
    {
        ['secret' => $secret, 'active' => $existing] = $this->branchData($branch, $session);
        if ($existing?->status->isActive()) {
            return $secret;
        }

        $details = app(BookingDetailsService::class)->validate($branch, $input);

        // Retain the pending key across retries so booking remains idempotent.
        $secret = $session->get('pending.'.$branch->id) ?? Str::random(64);
        $session->put('pending.'.$branch->id, $secret);
        $this->queue->book($branch, $secret, $secret, ['service_id' => $details['branch_service_id'], 'customer_phone' => $details['customer_phone']]);
        $session->put('ticket.'.$branch->id, $secret);
        $session->forget('pending.'.$branch->id);

        return $secret;
    }

    public function ticketData(string $secret): array
    {
        $ticket = $this->findTicket($secret);

        return ['ticket' => $ticket, 'secret' => $secret, 'snapshot' => $this->snapshot->ticket($ticket)];
    }

    public function state(string $secret): array
    {
        return $this->snapshot->ticket($this->findTicket($secret));
    }

    public function cancel(string $secret): void
    {
        $this->queue->transition($this->findTicket($secret), 'cancel');
    }

    public function findTicket(string $secret): Ticket
    {
        abort_unless(strlen($secret) === 64, 404);

        return Ticket::where('token_hash', hash('sha256', $secret))->firstOrFail();
    }
}
