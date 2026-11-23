<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Waiting = 'waiting';
    case Deferred = 'deferred';
    case Called = 'called';
    case Serving = 'serving';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function label(): string
    {
        $key = 'queue.status_label_' . $this->value;
        // Fall back to hard-coded Arabic if the key is somehow missing
        $fallbacks = [
            'waiting'   => 'Waiting',
            'called'    => 'Your turn',
            'deferred'  => 'Awaiting retry',
            'serving'   => 'Being served',
            'completed' => 'Completed',
            'no_show'   => 'No show',
            'cancelled' => 'Cancelled',
            'expired'   => 'Expired',
        ];
        $translated = __($key);
        return $translated !== $key ? $translated : ($fallbacks[$this->value] ?? $this->value);
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Waiting, self::Deferred, self::Called, self::Serving], true);
    }
}
