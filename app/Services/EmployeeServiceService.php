<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class EmployeeServiceService
{
    public function employee(User $actor, ?int $branchId, int $employeeId): User
    {
        $admin = User::findOrFail($actor->id);
        abort_unless($admin->is_active && $admin->role === 'admin', 403);
        abort_unless($branchId, 422);

        return User::where('branch_id', $branchId)->findOrFail($employeeId);
    }

    public function save(User $actor, ?int $branchId, int $employeeId, array $serviceIds): void
    {
        $this->employee($actor, $branchId, $employeeId);
        DB::transaction(function () use ($actor, $branchId, $employeeId, $serviceIds) {
            Branch::whereKey($branchId)->lockForUpdate()->firstOrFail();
            $employee = $this->employee($actor, $branchId, $employeeId);
            $data = Validator::make(['serviceIds' => $serviceIds], [
                'serviceIds' => 'present|array|max:200',
                'serviceIds.*' => ['required', 'integer', 'distinct', Rule::exists('branch_services', 'id')->where('branch_id', $branchId)],
            ], ['serviceIds.*.exists' => __('queue.err_employee_services_branch')])->validate();
            $employee->services()->sync($data['serviceIds']);
        }, 5);
    }

    public function eligible(Builder $tickets, User $user, int $branchId): Builder
    {
        $ids = $user->services()->where('branch_id', $branchId)->pluck('branch_services.id');

        // Disabling a service stops new bookings, but existing tickets must
        // still be served. Legacy tickets without a service remain shared.
        return $tickets->where(fn ($query) => $query->whereIn('branch_service_id', $ids)->orWhereNull('branch_service_id'))
            ->where(fn ($query) => $query->whereNull('appointment_at')->orWhere('appointment_at', '<=', now()));
    }
}
