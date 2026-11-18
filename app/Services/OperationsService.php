<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsService
{
    public function __construct(private readonly BranchAccessService $access) {}

    public function viewData(User $user, ?int $branchId): array
    {
        $branches = $this->access->branches($user);
        $branch = $branches->firstWhere('id', $branchId);

        return ['branches' => $branches, 'branch' => $branch, 'counters' => $branch?->counters()->with('user')->orderBy('number')->get() ?? collect(), 'employees' => User::with('services')->where('branch_id', $branch?->id ?? 0)->get(), 'counterSessions' => CounterSession::with(['counter', 'user'])->where('branch_id', $branch?->id ?? 0)->orderByDesc('id')->limit(20)->get()];
    }

    public function createBranch(User $user, array $input): Branch
    {
        abort_unless($this->access->canManage($user), 403);
        abort_unless($user->role === 'admin', 403);
        $data = Validator::make($input, ['branchName' => 'required|string|max:120', ...$this->hourRules()])->validate();

        return DB::transaction(function () use ($data) {
            $branch = Branch::create(['name' => $data['branchName'], 'opens_at' => $data['opensAt'], 'closes_at' => $data['closesAt'], 'timezone' => 'Africa/Cairo']);
            $branch->services()->create(['name' => 'خدمة عامة']);

            return $branch;
        });
    }

    public function saveSettings(User $user, ?int $branchId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $data = Validator::make($input, $this->hourRules())->validate();
        DB::transaction(function () use ($branch, $data) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->update(['opens_at' => $data['opensAt'], 'closes_at' => $data['closesAt']]);
        });
    }

    public function saveAutomation(User $actor, ?int $branchId, array $input): void
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->is_active && $admin->role === 'admin', 403);
        $data = Validator::make($input, ['automaticQueue' => 'required|boolean', 'noShowSeconds' => 'required|integer|min:30|max:900', 'missedTurnRetries' => 'sometimes|required|integer|min:0|max:10'])->validate();
        DB::transaction(function () use ($branchId, $data) {
            $branch = Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $branch->update(['automatic_queue' => $data['automaticQueue'], 'no_show_after_seconds' => $data['noShowSeconds']]);
            if (array_key_exists('missedTurnRetries', $data)) {
                $branch->update(['missed_turn_retries' => $data['missedTurnRetries']]);
            }
        }, 5);
    }

    public function toggleBooking(User $user, ?int $branchId): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $branch->update(['is_open' => ! $branch->is_open]);
    }

    public function createEmployee(User $user, ?int $branchId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $data = Validator::make($input + ['employeeRole' => 'agent'], ['employeeName' => 'required|string|max:120', 'employeeEmail' => 'required|email|max:255|unique:users,email', 'employeePassword' => 'required|string|min:12|max:128', 'employeeRole' => ['required', Rule::in($user->role === 'admin' ? ['agent', 'manager'] : ['agent'])]])->validate();
        User::create(['name' => $data['employeeName'], 'email' => $data['employeeEmail'], 'password' => $data['employeePassword'], 'role' => $data['employeeRole'], 'branch_id' => $branch->id]);
    }

    public function employeeData(User $user, ?int $branchId, int $employeeId): array
    {
        $employee = $this->employee($user, $branchId, $employeeId);

        return ['editEmployeeName' => $employee->name, 'editEmployeeEmail' => $employee->email, 'editEmployeeRole' => $employee->role, 'editEmployeeActive' => $employee->is_active, 'editEmployeePassword' => ''];
    }

    public function updateEmployee(User $user, ?int $branchId, int $employeeId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        DB::transaction(function () use ($user, $branch, $employeeId, $input) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $employee = $this->employee($user, $branch->id, $employeeId);
            $data = Validator::make($input, [
                'editEmployeeName' => 'required|string|max:120',
                'editEmployeeEmail' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee->id)],
                'editEmployeeRole' => ['required', Rule::in($user->role === 'admin' ? ['agent', 'manager', 'admin'] : ['agent'])],
                'editEmployeeActive' => 'required|boolean',
                'editEmployeePassword' => 'nullable|string|min:12|max:128',
            ])->validate();
            if ($employee->id === $user->id && (! $data['editEmployeeActive'] || $data['editEmployeeRole'] !== $employee->role)) {
                throw ValidationException::withMessages(['editEmployeeActive' => 'لا يمكنك تعطيل حسابك أو تغيير صلاحياته بنفسك.']);
            }
            $changesAccess = ! $data['editEmployeeActive'] || $data['editEmployeeRole'] !== $employee->role || filled($data['editEmployeePassword'] ?? null);
            if ($changesAccess && Counter::where('user_id', $employee->id)->whereHas('tickets', fn ($query) => $query->whereIn('status', ['called', 'serving']))->exists()) {
                throw ValidationException::withMessages(['editEmployeeActive' => 'أنهِ التذكرة الحالية قبل تغيير وصول الموظف أو كلمة مروره.']);
            }
            $values = ['name' => $data['editEmployeeName'], 'email' => $data['editEmployeeEmail'], 'role' => $data['editEmployeeRole'], 'is_active' => $data['editEmployeeActive']];
            if (filled($data['editEmployeePassword'] ?? null)) {
                $values['password'] = $data['editEmployeePassword'];
            }
            if ($changesAccess) {
                $values['remember_token'] = Str::random(60);
            }
            // Explicit assignment includes remember_token, which is intentionally
            // not available to mass-assigned forms.
            $employee->forceFill($values)->save();
            if (! $employee->is_active) {
                foreach (Counter::where('user_id', $employee->id)->get() as $counter) {
                    app(CounterSessionService::class)->closeForCounter($counter, 'account_disabled');
                }
            }
            if (! $employee->is_active && Counter::where('user_id', $employee->id)->where('is_paused', false)->update(['is_paused' => true])) {
                $branch->refresh()->increment('version');
                OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'counter_changed']);
                if (config('firebase.enabled')) {
                    PublishBranch::dispatch($branch->id)->afterCommit();
                }
            }
        });
    }

    private function employee(User $user, ?int $branchId, int $employeeId): User
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $employee = User::where('branch_id', $branch->id)->findOrFail($employeeId);
        abort_unless($user->role === 'admin' || $employee->role === 'agent', 403);

        return $employee;
    }

    public function createCounter(User $user, ?int $branchId, array $input): string
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $data = Validator::make($input, [
            'counterName' => 'required|string|max:120',
            'counterNumber' => ['required', 'integer', 'min:1', Rule::unique('counters', 'number')->where('branch_id', $branch->id)],
            'employeeId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('branch_id', $branch->id)->where('is_active', true), Rule::unique('counters', 'user_id')],
        ])->validate();
        $secret = Str::random(64);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => $data['counterName'], 'number' => $data['counterNumber'], 'user_id' => $data['employeeId'] ?? null, 'display_token_hash' => hash('sha256', $secret)]);

        return route('display.show', [$counter, $secret]);
    }

    public function rotateDisplay(User $user, ?int $branchId, int $counterId): string
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $counter = Counter::where('branch_id', $branch->id)->findOrFail($counterId);
        $secret = Str::random(64);
        $counter->update(['display_token_hash' => hash('sha256', $secret)]);

        return route('display.show', [$counter, $secret]);
    }

    public function counterData(User $user, ?int $branchId, int $counterId): array
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $counter = Counter::where('branch_id', $branch->id)->findOrFail($counterId);

        return ['editCounterName' => $counter->name, 'editCounterNumber' => $counter->number];
    }

    public function updateCounter(User $user, ?int $branchId, int $counterId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        DB::transaction(function () use ($branch, $counterId, $input) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $counter = Counter::where('branch_id', $branch->id)->lockForUpdate()->findOrFail($counterId);
            $data = Validator::make($input, ['editCounterName' => 'required|string|max:120', 'editCounterNumber' => ['required', 'integer', 'min:1', Rule::unique('counters', 'number')->where('branch_id', $branch->id)->ignore($counter->id)]])->validate();
            if ($counter->currentTicket()) {
                throw ValidationException::withMessages(['editCounterName' => 'أنهِ التذكرة الحالية قبل تعديل بيانات الشباك.']);
            }
            $counter->update(['name' => $data['editCounterName'], 'number' => $data['editCounterNumber']]);
            $branch->refresh()->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'counter_changed']);
            if (config('firebase.enabled')) {
                PublishBranch::dispatch($branch->id)->afterCommit();
            }
        });
    }

    public function assignEmployee(User $user, ?int $branchId, int $counterId, ?int $employeeId): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        Validator::make(['assignmentEmployeeId' => $employeeId], ['assignmentEmployeeId' => ['nullable', 'integer', Rule::exists('users', 'id')->where('branch_id', $branch->id)->where('is_active', true), Rule::unique('counters', 'user_id')->ignore($counterId)]])->validate();
        DB::transaction(function () use ($branch, $counterId, $employeeId) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $counter = Counter::where('branch_id', $branch->id)->lockForUpdate()->findOrFail($counterId);
            if ($counter->currentTicket()) {
                throw ValidationException::withMessages(['assignmentEmployeeId' => 'أنهِ التذكرة الحالية قبل تغيير الموظف.']);
            }
            if ($counter->user_id !== $employeeId) {
                app(CounterSessionService::class)->closeForCounter($counter, 'assignment_changed');
            }
            $counter->update(['user_id' => $employeeId ?: null]);
        });
    }

    public function createService(User $user, ?int $branchId, string $name): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $data = Validator::make(['serviceName' => trim($name)], ['serviceName' => ['required', 'string', 'max:120', Rule::unique('branch_services', 'name')->where('branch_id', $branch->id)]])->validate();
        DB::transaction(function () use ($branch, $data) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $branch->services()->create(['name' => $data['serviceName']]);
        });
    }

    public function toggleService(User $user, ?int $branchId, int $serviceId): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        DB::transaction(function () use ($branch, $serviceId) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $service = $branch->services()->findOrFail($serviceId);
            $service->update(['is_active' => ! $service->is_active]);
        });
    }

    private function hourRules(): array
    {
        return ['opensAt' => 'required|date_format:H:i', 'closesAt' => 'required|date_format:H:i|after:opensAt'];
    }
}
