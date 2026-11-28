<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\ValidationException;
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
        abort_if($expectedTicketId !== null && $ticket->id !== $expectedTicketId, 409, __('queue.err_client_changed'));

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
                && $counter->user_id === $currentUser->id, 403);
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

    public function requeueTicket(User $user, string $requestKey, int $ticketId, ?int $newBranchServiceId = null): Ticket
    {
        return $this->runCommand($user, $requestKey, 'requeue', $ticketId, function () use ($user, $ticketId, $newBranchServiceId) {
            $counter = $this->counter($user);
            abort_unless($counter, 403);
            $ticket = $counter->currentTicket();
            abort_unless($ticket && $ticket->id === $ticketId, 422);

            return $this->queue->requeue($ticket, $user, $newBranchServiceId ?: null);
        });
    }

    public function manualBooking(User $user, array $details = []): Ticket    {
        $counter = $this->counter($user);
        abort_unless($counter, 403);
        $secret = Str::random(64);

        return $this->queue->book($counter->branch, $secret, $secret, $details);
    }

    public function viewData(User $user): array
    {
        $counter = $this->counter($user);
        $branches = Branch::where('is_active', true)->orderBy('name')->get();
        $branchId = (int) session('staff.branch_id', request()->query('branch_id', $user->branch_id));
        if (! $branches->contains('id', $branchId)) {
            $branchId = $branches->first()?->id;
        }
        session(['staff.branch_id' => $branchId]);
        $counters = $branchId ? Counter::where('branch_id', $branchId)->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', $user->id))->orderBy('number')->get() : collect();

        return [
            'branches' => $branches, 'counters' => $counters, 'selectedBranchId' => $branchId,
            'savedServices' => $counter ? $user->services()->where('branch_id', $counter->branch_id)->get() : collect(),
            'shift' => $counter ? app(CounterSessionService::class)->active($counter) : null,
            'counter' => $counter, 'current' => $counter?->currentTicket(),
            'waiting' => $counter ? app(QueueOrderService::class)->order(app(EmployeeServiceService::class)->eligible(Ticket::where('branch_id', $counter->branch_id)->where('status', 'waiting')->whereDate('business_date', now($counter->branch->timezone)->toDateString()), $user, $counter->branch_id))->get() : collect(),
            'deferred' => $counter ? Ticket::where('branch_id', $counter->branch_id)->where('status', 'deferred')->whereDate('business_date', now($counter->branch->timezone)->toDateString())->where(fn ($query) => $query->whereIn('branch_service_id', $user->services()->pluck('branch_services.id'))->orWhereNull('branch_service_id'))->orderBy('deferred_at')->get() : collect(),
        ];
    }

    public function selectBranch(User $user, int $branchId): void
    {
        abort_unless($user->is_active && Branch::where('is_active', true)->whereKey($branchId)->exists(), 403);
        if ((int) session('staff.branch_id') !== $branchId) session()->forget('staff.counter_id');
        session(['staff.branch_id' => $branchId]);
    }

    public function selectCounter(User $user, int $branchId, int $counterId): void
    {
        abort_unless($user->is_active && Branch::where('is_active', true)->whereKey($branchId)->exists(), 403);
        DB::transaction(function () use ($user, $branchId, $counterId) {
            $target = Counter::where('branch_id', $branchId)->lockForUpdate()->findOrFail($counterId);
            abort_if($target->user_id && $target->user_id !== $user->id, 403);
            if ($target->currentTicket() && $target->user_id !== $user->id) {
                throw ValidationException::withMessages(['counter' => __('queue.rec_counter_busy')]);
            }
            $previous = Counter::where('user_id', $user->id)->lockForUpdate()->get();
            foreach ($previous as $counter) {
                if ($counter->id === $target->id) continue;
                if ($counter->currentTicket()) throw ValidationException::withMessages(['counter' => __('queue.rec_finish_current_first')]);
                app(CounterSessionService::class)->closeForCounter($counter, 'assignment_changed');
                $counter->update(['user_id' => null]);
            }
            $target->update(['user_id' => $user->id]);
        }, 5);
        session(['staff.branch_id' => $branchId, 'staff.counter_id' => $counterId]);
    }

    private function counter(User $user): ?Counter
    {
        abort_unless($user->is_active && in_array($user->role, ['admin', 'manager', 'agent'], true), 403);
        if (! session()->has('staff.counter_id')) return null;

        return Counter::where('user_id', $user->id)->whereKey(session('staff.counter_id'))->first();
    }
}
