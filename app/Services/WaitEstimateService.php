<?php

namespace App\Services;

use App\Models\Counter;
use App\Models\Ticket;

class WaitEstimateService
{
    public function estimate(Ticket $ticket): array
    {
        if ($ticket->status->value !== 'waiting') {
            return ['minutes' => null, 'samples' => 0, 'label' => ''];
        }
        if ($ticket->appointment_at?->isFuture()) {
            return ['minutes' => null, 'samples' => 0, 'label' => 'أولوية موعدك تبدأ الساعة '.$ticket->appointment_at->timezone($ticket->branch->timezone)->format('H:i').'.'];
        }
        // Recent completed services only, scoped to this branch and service.
        $samples = Ticket::where('branch_id', $ticket->branch_id)->where('branch_service_id', $ticket->branch_service_id)
            ->where('status', 'completed')->where('finished_at', '>=', now()->subDays(30))->whereNotNull('started_at')
            ->orderByDesc('finished_at')->limit(100)->get()->map(fn ($item) => $item->started_at->diffInSeconds($item->finished_at, false))
            ->filter(fn ($seconds) => $seconds > 0 && $seconds <= 14400);
        $counters = Counter::where('branch_id', $ticket->branch_id)->where('is_paused', false)
            ->whereHas('user', fn ($query) => $query->where('is_active', true)->whereIn('role', ['admin', 'manager', 'agent'])
                ->where(fn ($query) => $query->where('branch_id', $ticket->branch_id)->orWhere('role', 'admin'))
                ->when($ticket->branch_service_id, fn ($query) => $query->whereHas('services', fn ($query) => $query->where('branch_services.id', $ticket->branch_service_id))))->get();
        if ($samples->count() < 5 || $counters->isEmpty()) {
            return ['minutes' => null, 'samples' => $samples->count(), 'label' => $counters->isEmpty() ? 'التقدير غير متاح حتى يتوفر شباك مناسب.' : 'نجمع بيانات كافية لتقدير الانتظار.'];
        }
        $average = $samples->avg();
        $loads = $counters->map(function ($counter) use ($average) {
            $current = $counter->currentTicket();
            if (! $current) {
                return 0;
            }

            return $current->started_at ? max($average * .25, $average - $current->started_at->diffInSeconds(now())) : $average;
        })->all();
        // Distribute preceding customers to the earliest available counter.
        $ahead = $ticket->ahead();
        for ($i = 0; $i < $ahead; $i++) {
            $index = array_search(min($loads), $loads, true);
            $loads[$index] += $average;
        }
        $minutes = max(0, (int) ceil(min($loads) / 60));

        return ['minutes' => $minutes, 'samples' => $samples->count(), 'label' => "حوالي {$minutes} دقيقة — تقدير يتغير حسب توفر الموظفين والخدمات الأخرى."];
    }
}
