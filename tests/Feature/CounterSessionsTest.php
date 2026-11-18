<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Counter;
use App\Models\CounterSession;
use App\Models\User;
use App\Services\CloseBusinessDay;
use App\Services\CounterSessionService;
use App\Services\OperationsService;
use App\Services\ReceptionDeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CounterSessionsTest extends TestCase
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

    public function test_first_action_starts_one_session_and_employee_can_end_and_resume(): void
    {
        $desk = app(ReceptionDeskService::class);
        $desk->command($this->agent, (string) Str::uuid(), 'pause');
        $desk->manualCommand($this->agent, (string) Str::uuid());
        $this->assertDatabaseCount('counter_sessions', 1);
        $desk->command($this->agent, (string) Str::uuid(), 'end_shift');
        $this->assertNotNull(CounterSession::first()->ended_at);
        $this->assertTrue($this->counter->fresh()->is_paused);
        $desk->command($this->agent, (string) Str::uuid(), 'pause');
        $this->assertDatabaseCount('counter_sessions', 2);
        $this->assertSame(1, CounterSession::whereNull('ended_at')->count());
    }

    public function test_session_cannot_end_with_called_customer(): void
    {
        $desk = app(ReceptionDeskService::class);
        $desk->manualCommand($this->agent, (string) Str::uuid());
        $desk->command($this->agent, (string) Str::uuid(), 'next');
        try {
            $desk->command($this->agent, (string) Str::uuid(), 'end_shift');
            $this->fail('Active service must prevent ending the shift');
        } catch (ValidationException) {
            $this->assertNull(CounterSession::first()->ended_at);
        }
    }

    public function test_other_employee_cannot_start_same_counter_and_reassignment_closes_session(): void
    {
        $service = app(CounterSessionService::class);
        $service->start($this->counter, $this->agent);
        $other = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        try {
            $service->start($this->counter, $other);
            $this->fail('Other employee must be rejected');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $admin = User::factory()->create(['role' => 'admin']);
        app(OperationsService::class)->assignEmployee($admin, $this->branch->id, $this->counter->id, $other->id);
        $this->assertSame('assignment_changed', CounterSession::first()->end_reason);
        $service->start($this->counter->fresh(), $other);
        $this->assertSame(1, CounterSession::whereNull('ended_at')->count());
    }

    public function test_business_day_closes_idle_session(): void
    {
        app(CounterSessionService::class)->start($this->counter, $this->agent);
        $this->travelTo(now()->setTime(18, 0));
        app(CloseBusinessDay::class)->run($this->branch);
        $this->assertSame('day_closed', CounterSession::first()->end_reason);
        $this->assertNull(CounterSession::first()->active_user_id);
        $this->assertNull(CounterSession::first()->active_counter_id);
    }
}
