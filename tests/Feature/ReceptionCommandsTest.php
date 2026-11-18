<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\QueueService;
use App\Services\ReceptionDeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ReceptionCommandsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $agent;

    private Counter $counter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    private function book(): Ticket
    {
        $secret = Str::random(64);

        return app(QueueService::class)->book($this->branch, $secret, $secret);
    }

    public function test_replaying_next_after_service_finishes_does_not_call_another_customer(): void
    {
        $first = $this->book();
        $second = $this->book();
        $desk = app(ReceptionDeskService::class);
        $key = (string) Str::uuid();
        $desk->command($this->agent, $key, 'next');
        $desk->command($this->agent, (string) Str::uuid(), 'start', $first->id);
        $desk->command($this->agent, (string) Str::uuid(), 'complete', $first->id);
        $result = $desk->command($this->agent, $key, 'next');
        $this->assertSame($first->id, $result->id);
        $this->assertSame('waiting', $second->fresh()->status->value);
    }

    public function test_replaying_pause_or_manual_booking_only_runs_once(): void
    {
        $desk = app(ReceptionDeskService::class);
        $pauseKey = (string) Str::uuid();
        $desk->command($this->agent, $pauseKey, 'pause');
        $desk->command($this->agent, $pauseKey, 'pause');
        $this->assertTrue($this->counter->fresh()->is_paused);
        $bookKey = (string) Str::uuid();
        $first = $desk->manualCommand($this->agent, $bookKey);
        $second = $desk->manualCommand($this->agent, $bookKey);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_stale_ticket_action_cannot_change_new_customer(): void
    {
        $first = $this->book();
        $second = $this->book();
        $desk = app(ReceptionDeskService::class);
        $desk->command($this->agent, (string) Str::uuid(), 'next');
        $desk->command($this->agent, (string) Str::uuid(), 'start', $first->id);
        $desk->command($this->agent, (string) Str::uuid(), 'complete', $first->id);
        $desk->command($this->agent, (string) Str::uuid(), 'next');
        try {
            $desk->command($this->agent, (string) Str::uuid(), 'start', $first->id);
            $this->fail('Stale ticket should fail');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertSame('called', $second->fresh()->status->value);
    }

    public function test_reusing_command_key_for_different_action_is_rejected(): void
    {
        $this->book();
        $desk = app(ReceptionDeskService::class);
        $key = (string) Str::uuid();
        $desk->command($this->agent, $key, 'next');
        try {
            $desk->command($this->agent, $key, 'pause');
            $this->fail('Reused key should fail');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }
        $this->assertFalse($this->counter->fresh()->is_paused);
    }

    public function test_disabled_employee_cannot_run_command_with_stale_user_instance(): void
    {
        User::whereKey($this->agent->id)->update(['is_active' => false]);
        try {
            app(ReceptionDeskService::class)->manualCommand($this->agent, (string) Str::uuid());
            $this->fail('Disabled employee must be rejected');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseCount('tickets', 0);
    }
}
