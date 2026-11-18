<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReceptionDeskService
{
    public function __construct(private readonly QueueService $queue) {}

    public function act(User $user, string $action, ?int $expectedTicketId = null): ?Ticket
    {
        $counter = $this->counter($user);
        abort_unless($counter, 403);
        if ($action === 'end_shift') {
            app(CounterSessionService::class)->finish($counter, $user);

            return null;
        }
        if ($action === 'next') {
            return $this->queue->next($counter, $user);
        }
        if ($action === 'pause') {
            $this->queue->pause($counter, $user);

            return null;
        }
        abort_unless(in_array($action, ['start', 'complete', 'absent', 'recall'], true), 422);
        $ticket = $counter->currentTicket();
        abort_unless($ticket, 422);
        abort_if($expectedTicketId !== null && $ticket->id !== $expectedTicketId, 409, 'تغير العميل الحالي. حدّث الشاشة قبل تنفيذ الإجراء.');

        return $this->queue->transition($ticket, $action, $user);
    }

    public function command(User $user, string $requestKey, string $action, ?int $expectedTicketId = null): ?Ticket
    {
        return $this->runCommand($user, $requestKey, $action, $expectedTicketId, fn () => $this->act($user, $action, $expectedTicketId));
    }

    public function manualCommand(User $user, string $requestKey, array $details = []): Ticket
    {
        return $this->runCommand($user, $requestKey, 'manual', null, fn () => $this->manualBooking($user, $details)) ?? abort(409);
    }

    private function runCommand(User $user, string $requestKey, string $action, ?int $expectedTicketId, callable $execute): ?Ticket
    {
        abort_unless(Str::isUuid($requestKey), 422);
        $counter = $this->counter($user);
        abort_unless($counter, 403);

        return DB::transaction(function () use ($user, $requestKey, $action, $expectedTicketId, $execute, $counter) {
            Branch::whereKey($counter->branch_id)->lockForUpdate()->firstOrFail();
            $currentUser = User::findOrFail($user->id);
            abort_unless($currentUser->is_active && in_array($currentUser->role, ['admin', 'manager', 'agent'], true)
                && ($currentUser->role === 'admin' || $currentUser->branch_id === $counter->branch_id), 403);
            $receipt = DB::table('reception_commands')->where('user_id', $user->id)->where('request_key', $requestKey)->first();
            if ($receipt) {
                abort_unless($receipt->counter_id === $counter->id && $receipt->action === $action && $receipt->expected_ticket_id === $expectedTicketId, 409);

                return $receipt->result_ticket_id ? Ticket::find($receipt->result_ticket_id) : null;
            }
            if ($action !== 'end_shift') {
                app(CounterSessionService::class)->start($counter, $user);
            }
            $result = $execute();
            DB::table('reception_commands')->insert(['branch_id' => $counter->branch_id, 'counter_id' => $counter->id, 'user_id' => $user->id, 'request_key' => $requestKey, 'action' => $action, 'expected_ticket_id' => $expectedTicketId, 'result_ticket_id' => $result?->id, 'created_at' => now()]);

            return $result;
        }, 5);
    }

    public function manualBooking(User $user, array $details = []): Ticket
    {
        $counter = $this->counter($user);
        abort_unless($counter, 403);
        $secret = Str::random(64);

        return $this->queue->book($counter->branch, $secret, $secret, $details);
    }

    public function viewData(User $user): array
    {
        $counter = $this->counter($user);

        return [
            'savedServices' => $counter ? $user->services()->where('branch_id', $counter->branch_id)->get() : collect(),
            'shift' => $counter ? app(CounterSessionService::class)->active($counter) : null,
            'counter' => $counter, 'current' => $counter?->currentTicket(),
            'waiting' => $counter ? app(QueueOrderService::class)->order(app(EmployeeServiceService::class)->eligible(Ticket::where('branch_id', $counter->branch_id)->where('status', 'waiting')->whereDate('business_date', now($counter->branch->timezone)->toDateString()), $user, $counter->branch_id))->get() : collect(),
            'deferred' => $counter ? Ticket::where('branch_id', $counter->branch_id)->where('status', 'deferred')->whereDate('business_date', now($counter->branch->timezone)->toDateString())->where(fn ($query) => $query->whereIn('branch_service_id', $user->services()->pluck('branch_services.id'))->orWhereNull('branch_service_id'))->orderBy('deferred_at')->get() : collect(),
        ];
    }

    private function counter(User $user): ?Counter
    {
        abort_unless($user->is_active && in_array($user->role, ['admin', 'manager', 'agent'], true), 403);

        return Counter::where('user_id', $user->id)->when($user->role !== 'admin', fn ($query) => $query->where('branch_id', $user->branch_id))->first();
    }
}
