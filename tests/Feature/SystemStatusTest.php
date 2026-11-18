<?php

namespace Tests\Feature;

use App\Jobs\RecordWorkerHeartbeat;
use App\Models\Branch;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Services\QueueHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SystemStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_pending_counts_without_secret_values(): void
    {
        $this->withoutVite();
        $branch = Branch::create(['name' => 'Test']);
        OutboxMessage::create(['branch_id' => $branch->id, 'version' => 1, 'type' => 'test']);
        $admin = User::factory()->create(['role' => 'admin']);
        config(['firebase.web.apiKey' => 'must-not-be-rendered']);
        Cache::put('queue:scheduler-heartbeat', now()->toIso8601String(), 180);
        $report = app(QueueHealthService::class)->report($admin);
        $this->assertSame(1, $report['pendingEvents']);
        $this->assertNotNull($report['schedulerHeartbeat']);
        $this->actingAs($admin)->get('/admin/system-status')->assertOk()->assertDontSee('must-not-be-rendered');
    }

    public function test_agent_and_branch_manager_cannot_view_system_health(): void
    {
        $this->withoutVite();
        foreach (['agent', 'manager'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->get('/admin/system-status')->assertForbidden();
        }
    }

    public function test_worker_probe_records_processing_heartbeat(): void
    {
        Cache::forget('queue:worker-heartbeat');
        (new RecordWorkerHeartbeat)->handle();
        $this->assertNotNull(Cache::get('queue:worker-heartbeat'));
    }
}
