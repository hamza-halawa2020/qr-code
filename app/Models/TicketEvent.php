<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'from_status' => TicketStatus::class, 'to_status' => TicketStatus::class];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        $key = 'queue.event_' . $this->type;
        $translated = __($key);
        if ($translated !== $key) {
            return $translated;
        }
        // Arabic fallbacks for unrecognised types
        return match ($this->type) {
            'booked'       => __('queue.event_booked'),
            'called'       => __('queue.event_called'),
            'recalled'     => __('queue.event_recalled'),
            'start'        => __('queue.event_start'),
            'complete'     => __('queue.event_complete'),
            'absent'       => __('queue.event_absent'),
            'cancel'       => __('queue.event_cancel'),
            'day_closed'   => __('queue.event_day_closed'),
            'deferred'     => __('queue.event_deferred'),
            'retry_ready'  => __('queue.event_retry_ready'),
            'retry_called' => __('queue.event_retry_called'),
            'requeued'     => __('queue.event_requeued'),
            default        => $this->type,
        };
    }
}
