<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'day_of_week' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function dayLabel(): string
    {
        return __('queue.day_'.(self::DAY_KEYS[$this->day_of_week] ?? 'sun'));
    }

    public function hoursLabel(): string
    {
        return $this->is_closed
            ? __('queue.day_closed')
            : ($this->opens_at ?? '—').' — '.($this->closes_at ?? '—');
    }
}
