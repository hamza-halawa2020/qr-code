<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $guarded = [];
    protected $hidden = ['token_hash', 'booking_key'];

    protected function casts(): array
    {
        return ['status' => TicketStatus::class, 'business_date' => 'date', 'called_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function counter(): BelongsTo { return $this->belongsTo(Counter::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function getDisplayNumberAttribute(): string { return str_pad((string) $this->number, 3, '0', STR_PAD_LEFT); }

    public function ahead(): int
    {
        if ($this->status !== TicketStatus::Waiting) { return 0; }

        return self::where('branch_id', $this->branch_id)->whereDate('business_date', $this->business_date)
            ->where('status', 'waiting')->where('number', '<', $this->number)->count();
    }
}
