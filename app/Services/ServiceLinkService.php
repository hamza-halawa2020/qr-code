<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ServiceLinkService
{
    public function __construct(private readonly BranchAccessService $access) {}

    /** Select which global services a branch offers; unselected ones stay linked but inactive. */
    public function save(User $user, ?int $branchId, array $serviceIds): void
    {
        $branch = $this->access->authorizedBranch($user, $branchId);
        $ids = Validator::make(['services' => $serviceIds], [
            'services' => 'array',
            'services.*' => ['integer', 'distinct', 'exists:services,id'],
        ])->validate()['services'] ?? [];

        DB::transaction(function () use ($branch, $ids) {
            Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            Service::whereIn('id', $ids)->lockForUpdate()->get();

            foreach ($ids as $id) {
                $link = BranchService::firstOrNew(['branch_id' => $branch->id, 'service_id' => $id]);
                if (! $link->exists || ! $link->is_active) {
                    $link->is_active = true;
                    $link->save();
                }
            }

            BranchService::where('branch_id', $branch->id)
                ->when($ids !== [], fn ($query) => $query->whereNotIn('service_id', $ids))
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });
    }
}
