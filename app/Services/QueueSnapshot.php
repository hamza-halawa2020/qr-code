<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\Counter;
use App\Models\Ticket;

class QueueSnapshot
{
    public function ticket(Ticket $ticket): array
    {
        $ticket->load('counter', 'branch');

        return [
            'id' => $ticket->public_id, 'number' => $ticket->display_number,
            'status' => $ticket->status->value, 'label' => $ticket->status->label(),
            'heading' => $ticket->status === TicketStatus::Waiting ? __('queue.status_heading_waiting') : $ticket->status->label(),
            'description' => match ($ticket->status) {
                TicketStatus::Called    => __('queue.status_desc_called'),
                TicketStatus::Serving   => __('queue.status_desc_serving'),
                TicketStatus::Completed => __('queue.status_desc_completed'),
                TicketStatus::Cancelled => __('queue.status_desc_cancelled'),
                TicketStatus::NoShow    => __('queue.status_desc_no_show'),
                TicketStatus::Deferred  => __('queue.status_desc_deferred'),
                TicketStatus::Expired   => __('queue.status_desc_expired'),
                default                 => __('queue.status_desc_waiting'),
            },
            'ahead' => $ticket->status === TicketStatus::Deferred ? null : ($ticket->status->value === 'waiting' ? $ticket->ahead() : 0),
            'ahead_label' => $ticket->status === TicketStatus::Deferred
                ? __('queue.ahead_label_deferred')
                : ($ticket->branch_service_id ? __('queue.ahead_label_service') : __('queue.ahead_label_legacy')),
            'retry_note' => $ticket->retry_limit ? __('queue.retry_note', ['used' => $ticket->retry_attempts, 'limit' => $ticket->retry_limit]) : '',
            'near_turn' => app(CustomerExperienceService::class)->isNear($ticket),
            'estimate' => app(WaitEstimateService::class)->estimate($ticket)['label'],
            'counter' => $ticket->counter?->name, 'version' => $ticket->branch->version,
            'updated_at' => now()->toIso8601String(),
        ];
    }

    public function counter(Counter $counter): array
    {
        return [
            'name' => $counter->name, 'paused' => $counter->is_paused,
            'number' => $counter->currentTicket()?->display_number,
            'version' => $counter->branch->version, 'updated_at' => now()->toIso8601String(),
        ];
    }
}
