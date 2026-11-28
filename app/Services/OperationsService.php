<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\Service;
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

        return [
            'branches' => $branches,
            'branch' => $branch, 
            'allServices' => Service::orderBy('name')->get(), 
            'counters' => $branch?->counters()->with('user')->orderBy('number')->get() ?? collect(), 
            'employees' => User::with('services')->where('branch_id', $branch?->id ?? 0)->get(), 
        ];
    }

    public function createBranch(User $user, array $input): Branch
    {
        abort_unless($this->access->canManage($user), 403);
        abort_unless($user->role === 'admin', 403);
        $data = Validator::make($input, ['branchName' => 'required|string|max:120'])->validate();
        $weekly = $this->prepareWeeklyHours($input);
        $default = collect($weekly)->first(fn (array $row) => ! $row['is_closed']) ?? ['opens_at' => '09:00', 'closes_at' => '17:00'];

        return DB::transaction(function () use ($data, $weekly, $default) {
            $branch = Branch::create([
                'name'      => $data['branchName'],
                'opens_at'  => $default['opens_at'],
                'closes_at' => $default['closes_at'],
                'timezone'  => 'Africa/Cairo',
            ]);
            foreach ($weekly as $day => $row) {
                $branch->schedules()->create(['day_of_week' => $day] + $row);
            }
            $general = Service::firstOrCreate(['name' => __('queue.err_service_general')]);
            $branch->services()->create(['service_id' => $general->id]);

            return $branch;
        });
    }

    public function toggleBranchActive(User $user, ?int $branchId): void
    {
        abort_unless($user->is_active && $user->role === 'admin', 403);
        $branch = Branch::whereKey($branchId)->firstOrFail();
        $branch->update(['is_active' => ! $branch->is_active, 'is_open' => false]);
    }

    public function deleteBranch(User $user, ?int $branchId): void
    {
        abort_unless($user->is_active && $user->role === 'admin', 403);
        $branch = Branch::whereKey($branchId)->firstOrFail();
        if ($branch->tickets()->exists() || $branch->counters()->exists() || $branch->appointmentSlots()->exists() || User::where('branch_id', $branch->id)->exists() || DB::table('daily_sequences')->where('branch_id', $branch->id)->exists()) {
            throw ValidationException::withMessages(['branch' => __('queue.ops_branch_delete_blocked')]);
        }
        $branch->delete();
    }

    public function saveSettings(User $user, ?int $branchId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $weekly = $this->prepareWeeklyHours($input);
        DB::transaction(fn () => $this->persistWeeklyHours($branch, $weekly));
    }

    public function saveWeeklyHours(User $user, ?int $branchId, array $input): void
    {
        $this->saveSettings($user, $branchId, ['weeklyHours' => $input['weeklyHours'] ?? []]);
    }

    public function saveAutomation(User $actor, ?int $branchId, array $input): void
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->is_active && $admin->role === 'admin', 403);
        $data = Validator::make($input, ['automaticQueue' => 'required|boolean', 'noShowSeconds' => 'required|integer|min:30|max:900', 'missedTurnRetries' => 'sometimes|required|integer|min:0|max:10', 'displayLocale' => 'sometimes|in:ar,en', 'panelLocale' => 'sometimes|in:ar,en'])->validate();
        DB::transaction(function () use ($branchId, $data) {
            $branch = Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $branch->update(['automatic_queue' => $data['automaticQueue'], 'no_show_after_seconds' => $data['noShowSeconds']]);
            if (array_key_exists('missedTurnRetries', $data)) {
                $branch->update(['missed_turn_retries' => $data['missedTurnRetries']]);
            }
            if (array_key_exists('displayLocale', $data)) {
                $branch->update(['display_locale' => $data['displayLocale']]);
            }
            if (array_key_exists('panelLocale', $data)) {
                $branch->update(['panel_locale' => $data['panelLocale']]);
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
        $data = Validator::make($input + ['employeeRole' => 'agent'], ['employeeName' => 'required|string|max:120', 'employeePhone' => 'required|string|max:30|unique:users,phone', 'employeePassword' => 'required|string|min:12|max:128', 'employeeRole' => ['required', Rule::in($user->role === 'admin' ? ['agent', 'manager'] : ['agent'])]])->validate();
        User::create(['name' => $data['employeeName'], 'phone' => $data['employeePhone'], 'password' => $data['employeePassword'], 'role' => $data['employeeRole'], 'branch_id' => $branch->id]);
    }

    public function employeeData(User $user, ?int $branchId, int $employeeId): array
    {
        $employee = $this->employee($user, $branchId, $employeeId);

        return ['editEmployeeName' => $employee->name, 'editEmployeePhone' => $employee->phone, 'editEmployeeRole' => $employee->role, 'editEmployeeBranchId' => $employee->branch_id, 'editEmployeeActive' => $employee->is_active, 'editEmployeePassword' => ''];
    }

    public function updateEmployee(User $user, ?int $branchId, int $employeeId, array $input): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        DB::transaction(function () use ($user, $branch, $employeeId, $input) {
            $employee = $this->employee($user, $branch->id, $employeeId);
            $data = Validator::make($input, [
                'editEmployeeName' => 'required|string|max:120',
                'editEmployeePhone' => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($employee->id)],
                'editEmployeeRole' => ['required', Rule::in($user->role === 'admin' ? ['agent', 'manager', 'admin'] : ['agent'])],
                'editEmployeeBranchId' => ['required', 'integer', Rule::exists('branches', 'id')],
                'editEmployeeActive' => 'required|boolean',
                'editEmployeePassword' => 'nullable|string|min:12|max:128',
            ], ['editEmployeeBranchId.exists' => __('queue.ops_transfer_branch_invalid')])->validate();

            // Managers can only edit employees in their own branch, even if the
            // branch selector is tampered with in the browser.
            $targetBranchId = $user->role === 'admin' ? (int) $data['editEmployeeBranchId'] : $employee->branch_id;
            $transferring = $targetBranchId !== $employee->branch_id;

            if ($employee->id === $user->id && ($transferring || ! $data['editEmployeeActive'] || $data['editEmployeeRole'] !== $employee->role)) {
                throw ValidationException::withMessages(['editEmployeeBranchId' => __('queue.ops_transfer_self')]);
            }

            $changesAccess = $transferring || ! $data['editEmployeeActive'] || $data['editEmployeeRole'] !== $employee->role || filled($data['editEmployeePassword'] ?? null);
            $hasCurrentTicket = Counter::where('user_id', $employee->id)->whereHas('tickets', fn ($query) => $query->whereIn('status', ['called', 'serving']))->exists();
            if ($changesAccess && $hasCurrentTicket) {
                $field = $transferring ? 'editEmployeeBranchId' : 'editEmployeeActive';
                $message = $transferring ? __('queue.ops_transfer_active_ticket') : __('queue.err_employee_ticket_access');
                throw ValidationException::withMessages([$field => $message]);
            }

            // Lock both branches in a stable order to keep concurrent edits safe.
            $branchIds = array_values(array_unique([$branch->id, $targetBranchId]));
            sort($branchIds);
            $locked = Branch::whereIn('id', $branchIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $oldBranch = $locked->get($branch->id);
            $targetBranch = $locked->get($targetBranchId);
            abort_unless($oldBranch && $targetBranch, 422);

            $values = [
                'name' => $data['editEmployeeName'],
                'phone' => $data['editEmployeePhone'],
                'role' => $data['editEmployeeRole'],
                'branch_id' => $targetBranchId,
                'is_active' => $data['editEmployeeActive'],
            ];
            if (filled($data['editEmployeePassword'] ?? null)) {
                $values['password'] = $data['editEmployeePassword'];
            }
            if ($changesAccess) {
                $values['remember_token'] = Str::random(60);
            }
            // Explicit assignment includes remember_token, which is intentionally
            // not available to mass-assigned forms.
            $employee->forceFill($values)->save();

            $counters = Counter::where('user_id', $employee->id)->get();
            if ($transferring) {
                foreach ($counters as $counter) {
                    app(CounterSessionService::class)->closeForCounter($counter, 'employee_transferred');
                    $counter->update(['user_id' => null, 'is_paused' => true]);
                }

                // Keep only services that also exist (and are active) in the new branch.
                $globalServiceIds = DB::table('employee_services')
                    ->join('branch_services', 'branch_services.id', '=', 'employee_services.branch_service_id')
                    ->where('employee_services.user_id', $employee->id)
                    ->pluck('branch_services.service_id')
                    ->unique();
                $newLinks = DB::table('branch_services')
                    ->where('branch_id', $targetBranchId)
                    ->where('is_active', true)
                    ->whereIn('service_id', $globalServiceIds)
                    ->pluck('id');
                $employee->services()->sync($newLinks);

                if ($counters->isNotEmpty()) {
                    $oldBranch->increment('version');
                    OutboxMessage::create(['branch_id' => $oldBranch->id, 'version' => $oldBranch->version, 'type' => 'counter_changed']);
                    if (config('firebase.enabled')) {
                        PublishBranch::dispatch($oldBranch->id)->afterCommit();
                    }
                }
            }

            if (! $employee->is_active) {
                foreach ($counters as $counter) {
                    app(CounterSessionService::class)->closeForCounter($counter, 'account_disabled');
                }
            }
            if (! $employee->is_active && Counter::where('user_id', $employee->id)->where('is_paused', false)->update(['is_paused' => true])) {
                $oldBranch->increment('version');
                OutboxMessage::create(['branch_id' => $oldBranch->id, 'version' => $oldBranch->version, 'type' => 'counter_changed']);
                if (config('firebase.enabled')) {
                    PublishBranch::dispatch($oldBranch->id)->afterCommit();
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
        ], ['counterNumber.unique' => __('queue.ops_counter_number_taken')])->validate();
        $secret = Str::random(64);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => $data['counterName'], 'number' => $data['counterNumber'], 'user_id' => null, 'display_token_hash' => hash('sha256', $secret), 'display_token' => encrypt($secret)]);

        return route('display.show', [$counter, $secret]);
    }

    public function rotateDisplay(User $user, ?int $branchId, int $counterId): string
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $counter = Counter::where('branch_id', $branch->id)->findOrFail($counterId);
        $secret = Str::random(64);
        $counter->update(['display_token_hash' => hash('sha256', $secret), 'display_token' => encrypt($secret)]);

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
            $data = Validator::make($input, ['editCounterName' => 'required|string|max:120', 'editCounterNumber' => ['required', 'integer', 'min:1', Rule::unique('counters', 'number')->where('branch_id', $branch->id)->ignore($counter->id)]], ['editCounterNumber.unique' => __('queue.ops_counter_number_taken')])->validate();
            if ($counter->currentTicket()) {
                throw ValidationException::withMessages(['editCounterName' => __('queue.err_counter_ticket_edit')]);
            }
            $counter->update(['name' => $data['editCounterName'], 'number' => $data['editCounterNumber']]);
            $branch->refresh()->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'counter_changed']);
            if (config('firebase.enabled')) {
                PublishBranch::dispatch($branch->id)->afterCommit();
            }
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

    /** Accept the new weekly form, or fall back to the old opensAt/closesAt pair. */
    private function prepareWeeklyHours(array $input): array
    {
        // Always validate the legacy pair when it is supplied. It is still the
        // fallback/default used by older callers and keeps old form errors intact.
        $legacy = array_key_exists('opensAt', $input) && array_key_exists('closesAt', $input)
            ? Validator::make($input, $this->hourRules())->validate()
            : null;

        if (isset($input['weeklyHours']) && is_array($input['weeklyHours'])) {
            return $this->validateWeeklyHours($input['weeklyHours']);
        }

        abort_unless($legacy !== null, 422);

        return collect(range(0, 6))->mapWithKeys(fn (int $day) => [$day => [
            'is_closed' => false,
            'opens_at'  => $legacy['opensAt'],
            'closes_at' => $legacy['closesAt'],
        ]])->all();
    }

    private function validateWeeklyHours(array $weekly): array
    {
        $rows = [];
        $errors = [];

        foreach (range(0, 6) as $day) {
            $row = $weekly[$day] ?? null;
            if (! is_array($row)) {
                $errors["weeklyHours.$day"] = [__('queue.err_hours_day_required')];
                continue;
            }

            $closed = filter_var($row['is_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($closed) {
                $rows[$day] = ['is_closed' => true, 'opens_at' => null, 'closes_at' => null];
                continue;
            }

            $validator = Validator::make($row, [
                'opens_at'  => 'required|date_format:H:i',
                'closes_at' => 'required|date_format:H:i|after:opens_at',
            ]);
            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    $errors["weeklyHours.$day.$field"] = $messages;
                }
                continue;
            }

            $data = $validator->validated();
            $rows[$day] = ['is_closed' => false, 'opens_at' => $data['opens_at'], 'closes_at' => $data['closes_at']];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    private function persistWeeklyHours(Branch $branch, array $weekly): void
    {
        $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
        $default = collect($weekly)->first(fn (array $row) => ! $row['is_closed']) ?? ['opens_at' => '09:00', 'closes_at' => '17:00'];
        $branch->update(['opens_at' => $default['opens_at'], 'closes_at' => $default['closes_at']]);

        foreach ($weekly as $day => $row) {
            $branch->schedules()->updateOrCreate(['day_of_week' => $day], $row);
        }

        // The public board reads hours from Firebase, so publish the new schedule.
        $branch->increment('version');
        OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'hours_changed']);
        if (config('firebase.enabled')) {
            PublishBranch::dispatch($branch->id)->afterCommit();
        }
    }

    private function hourRules(): array
    {
        return ['opensAt' => 'required|date_format:H:i', 'closesAt' => 'required|date_format:H:i|after:opensAt'];
    }
}
