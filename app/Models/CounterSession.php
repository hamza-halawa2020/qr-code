<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CounterSession extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'started_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function endReasonLabel(): string
    {
        $key = 'queue.reason_' . ($this->end_reason ?? 'active');
        $translated = __($key);
        if ($translated !== $key) {
            return $translated;
        }
        return match ($this->end_reason) {
            'employee_finished' => __('queue.reason_employee_finished'),
            'assignment_changed'=> __('queue.reason_assignment_changed'),
            'account_disabled'  => __('queue.reason_account_disabled'),
            'day_closed'        => __('queue.reason_day_closed'),
            'day_changed'       => __('queue.reason_day_changed'),
            default             => __('queue.reason_active'),
        };
    }
}
