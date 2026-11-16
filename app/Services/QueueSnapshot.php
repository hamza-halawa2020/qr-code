<?php

namespace App\Services;

use App\Models\{Counter, Ticket};

class QueueSnapshot
{
    public function ticket(Ticket $ticket): array
    {
        $ticket->load('counter', 'branch');
        return [
            'id' => $ticket->public_id, 'number' => $ticket->display_number,
            'status' => $ticket->status->value, 'label' => $ticket->status->label(),
            'ahead' => $ticket->status->value === 'waiting' ? $ticket->ahead() : 0,
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
