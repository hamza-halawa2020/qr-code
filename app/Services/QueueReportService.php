<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\CounterSession;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class QueueReportService
{
    public function __construct(private readonly BranchAccessService $access) {}

    public function daily(User $user, string $date, ?int $branchId, ?string $status = null, int $page = 1): array
    {
        $status = $status === '' ? null : $status;
        Validator::make(['date' => $date, 'branchId' => $branchId, 'status' => $status, 'page' => $page], ['date' => 'required|date_format:Y-m-d', 'branchId' => 'nullable|integer', 'status' => ['nullable', Rule::enum(TicketStatus::class)], 'page' => 'integer|min:1'])->validate();
        $branches = $this->access->branches($user);
        $query = Ticket::with(['counter', 'user', 'branch'])->whereIn('branch_id', $branches->pluck('id'))
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->whereDate('business_date', $date);
        $tickets = (clone $query)->get();
        $ratings = $tickets->whereNotNull('rating');
        $serviceStats = $tickets->groupBy(fn ($ticket) => $ticket->service_name ?? 'غير محددة')->map(function ($items, $name) {
            $done = $items->where('status', TicketStatus::Completed);
            $durations = $done->filter(fn ($ticket) => $ticket->started_at && $ticket->finished_at)->map(fn ($ticket) => $ticket->started_at->diffInSeconds($ticket->finished_at) / 60);

            return ['name' => $name, 'total' => $items->count(), 'completed' => $done->count(), 'noShow' => $items->where('status', TicketStatus::NoShow)->count(), 'serviceMinutes' => $durations->isEmpty() ? null : round($durations->avg(), 1), 'rating' => $items->whereNotNull('rating')->isEmpty() ? null : round($items->whereNotNull('rating')->avg('rating'), 1)];
        })->values();
        $wait = $tickets->filter(fn ($ticket) => $ticket->started_at)->map(fn ($ticket) => $ticket->created_at->diffInSeconds($ticket->started_at));
        $service = $tickets->filter(fn ($ticket) => $ticket->status->value === 'completed' && $ticket->started_at && $ticket->finished_at)->map(fn ($ticket) => $ticket->started_at->diffInSeconds($ticket->finished_at));
        $employeeStats = $tickets->where('status', TicketStatus::Completed)->groupBy('user_id')->map(function ($served) {
            $durations = $served->filter(fn ($ticket) => $ticket->started_at && $ticket->finished_at)
                ->map(fn ($ticket) => $ticket->started_at->diffInSeconds($ticket->finished_at));

            return ['name' => $served->first()->user?->name ?? 'حساب غير متاح', 'completed' => $served->count(), 'serviceMinutes' => $durations->isEmpty() ? null : round($durations->avg() / 60, 1)];
        })->sortByDesc('completed')->values();
        $hourlyStats = $tickets->countBy(fn ($ticket) => $ticket->created_at->timezone($ticket->branch->timezone)->format('H:00'))->sortKeys();
        $ticketRows = (clone $query)->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(25, ['*'], 'page', $page);

        $counterSessions = CounterSession::with(['counter', 'user'])
            ->whereIn('branch_id', $branches->pluck('id'))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereDate('started_at', $date)
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return ['serviceStats' => $serviceStats, 'ratings' => $ratings, 'ratingCount' => $ratings->count(), 'ratingAverage' => $ratings->isEmpty() ? null : round($ratings->avg('rating'), 1), 'branches' => $branches, 'tickets' => $tickets, 'ticketRows' => $ticketRows, 'employeeStats' => $employeeStats, 'hourlyStats' => $hourlyStats, 'peakHour' => $hourlyStats->sortDesc()->keys()->first(), 'counts' => $tickets->countBy(fn ($ticket) => $ticket->status->value), 'waitMinutes' => round(($wait->avg() ?? 0) / 60, 1), 'serviceMinutes' => round(($service->avg() ?? 0) / 60, 1), 'counterSessions' => $counterSessions];
    }

    public function history(User $user, int $ticketId): array
    {
        $branches = $this->access->branches($user);
        $ticket = Ticket::with(['branch', 'counter', 'user'])->whereIn('branch_id', $branches->pluck('id'))->findOrFail($ticketId);
        $events = TicketEvent::with('user:id,name')->where('ticket_id', $ticket->id)->orderBy('id')->get();

        return compact('ticket', 'events');
    }
}
