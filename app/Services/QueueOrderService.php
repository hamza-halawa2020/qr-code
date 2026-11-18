<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

class QueueOrderService
{
    public function order(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN appointment_at IS NOT NULL THEN 0 WHEN retry_ready_at IS NOT NULL THEN 1 ELSE 2 END')
            ->orderBy('appointment_at')->orderBy('retry_ready_at')->orderBy('number');
    }
}
