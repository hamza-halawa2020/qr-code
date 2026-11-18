<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class BranchAccessService
{
    public function canManage(?User $user): bool
    {
        return $user?->is_active && in_array($user->role, ['admin', 'manager'], true);
    }

    public function branches(User $user): Collection
    {
        abort_unless($this->canManage($user), 403);

        return Branch::query()->when($user->role !== 'admin', fn ($query) => $query->whereKey($user->branch_id))->orderBy('name')->get();
    }

    public function authorizedBranch(User $user, ?int $branchId): Branch
    {
        return $this->branches($user)->firstWhere('id', $branchId) ?? abort(403);
    }
}
