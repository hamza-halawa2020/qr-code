<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Ticket extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash', 'booking_key', 'customer_phone', 'customer_name', 'rating_comment'];

    protected function casts(): array
    {
        return ['customer_phone' => 'encrypted', 'customer_name' => 'encrypted', 'appointment_at' => 'datetime', 'near_notified_at' => 'datetime', 'rated_at' => 'datetime', 'deferred_at' => 'datetime', 'retry_ready_at' => 'datetime', 'status' => TicketStatus::class, 'business_date' => 'date', 'called_at' => 'datetime', 'call_expires_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function callDeadline(): Carbon
    {
        return $this->call_expires_at ?? $this->called_at->copy()->addSeconds($this->branch->no_show_after_seconds);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getDisplayNumberAttribute(): string
    {
        return str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }

    public function ahead(): int
    {
        if ($this->status !== TicketStatus::Waiting) {
            return 0;
        }

        if ($this->appointment_at?->isFuture()) {
            return 0;
        }
        $query = self::where('branch_id', $this->branch_id)->whereDate('business_date', $this->business_date)
            ->where('branch_service_id', $this->branch_service_id)->where('status', 'waiting');
        if ($this->appointment_at) {
            $query->whereNotNull('appointment_at')->where('appointment_at', '<=', now())->where(fn ($q) => $q->where('appointment_at', '<', $this->appointment_at)->orWhere(fn ($q) => $q->where('appointment_at', $this->appointment_at)->where('number', '<', $this->number)));
        } elseif ($this->retry_ready_at) {
            $query->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('appointment_at')->where('appointment_at', '<=', now()))->orWhere(fn ($q) => $q->whereNull('appointment_at')->whereNotNull('retry_ready_at')->where(fn ($q) => $q->where('retry_ready_at', '<', $this->retry_ready_at)->orWhere(fn ($q) => $q->where('retry_ready_at', $this->retry_ready_at)->where('number', '<', $this->number)))));
        } else {
            $query->where(fn ($q) => $q->where(fn ($q) => $q->whereNull('appointment_at')->where(fn ($q) => $q->whereNotNull('retry_ready_at')->orWhere('number', '<', $this->number)))->orWhere(fn ($q) => $q->whereNotNull('appointment_at')->where('appointment_at', '<=', now())));
        }

        return $query->count();
    }
}
