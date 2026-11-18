<?php

namespace App\Services;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BranchBoardService
{
    public function rotate(User $user, ?int $branchId): string
    {
        $branch = app(BranchAccessService::class)->authorizedBranch($user, $branchId);

        return DB::transaction(function () use ($branch) {
            $branch = Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $secret = Str::random(64);
            $branch->update(['board_token_hash' => hash('sha256', $secret)]);
            $branch->increment('version');
            OutboxMessage::create(['branch_id' => $branch->id, 'version' => $branch->version, 'type' => 'board_changed']);
            if (config('firebase.enabled')) {
                PublishBranch::dispatch($branch->id)->afterCommit();
            }

            return route('board.show', [$branch, $secret]);
        });
    }

    public function state(Branch $branch, string $secret): array
    {
        $this->authorize($branch, $secret);

        return $this->snapshot($branch);
    }

    public function credentials(Branch $branch, string $secret): array
    {
        $this->authorize($branch, $secret);
        abort_unless(config('firebase.enabled'), 503);

        return ['token' => app(FirebaseGateway::class)->customToken('board-'.$branch->id, ['branch_display_id' => (string) $branch->id]), 'path' => 'branch_displays/'.$branch->id];
    }

    public function snapshot(Branch $branch): array
    {
        return ['name' => $branch->name, 'version' => $branch->version, 'updated_at' => now()->toIso8601String(),
            'counters' => $branch->counters()->orderBy('number')->get()->map(fn ($counter) => ['id' => $counter->id, 'name' => $counter->name, 'number' => $counter->currentTicket()?->display_number, 'status' => $counter->currentTicket()?->status->label() ?? ($counter->is_paused ? __('queue.board_counter_paused') : __('queue.board_counter_available'))])->all()];
    }

    private function authorize(Branch $branch, string $secret): void
    {
        abort_unless(strlen($secret) === 64 && $branch->board_token_hash && hash_equals($branch->board_token_hash, hash('sha256', $secret)), 404);
    }
}
