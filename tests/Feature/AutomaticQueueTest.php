<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations;
use App\Jobs\HandleCallTimeout;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomaticQueueService;
use App\Services\OperationsService;
use App\Services\QueueService;
use App\Services\ReceptionDeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AutomaticQueueTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $agent;
    private Counter $counter;
    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake([HandleCallTimeout::class]);
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Auto', 'timezone' => 'UTC', 'automatic_queue' => true, 'no_show_after_seconds' => 30]);
        $this->serviceId = $this->branch->services()->create(['name' => 'Supported'])->id;
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->agent->services()->sync([$this->serviceId]);
        $this->counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    private function book(?int $serviceId = null): Ticket
    {
        return app(QueueService::class)->book($this->branch, $secret = Str::random(64), $secret, ['service_id' => $serviceId ?? $this->serviceId]);
    }

    private function callFirst(): Ticket
    {
        return app(QueueService::class)->next($this->counter, $this->agent);
    }

    public function test_timeout_marks_no_show_and_calls_oldest_matching_customer_once(): void
    {
        $first = $this->book();
        $otherService = $this->branch->services()->create(['name' => 'Other']);
        $unsupported = $this->book($otherService->id);
        $next = $this->book();
        $this->callFirst();
        $automatic = app(AutomaticQueueService::class);
        $this->assertFalse($automatic->expire($first->id));
        $this->travel(30)->seconds();
        $this->assertTrue($automatic->expire($first->id));
        $this->assertSame('no_show', $first->fresh()->status->value);
        $this->assertSame('called', $next->fresh()->status->value);
        $this->assertSame('waiting', $unsupported->fresh()->status->value);
        $this->assertFalse($automatic->expire($first->id));
        $this->assertSame($next->id, $this->counter->currentTicket()->id);
        $this->assertDatabaseCount('ticket_events', 6); // 3 bookings, first call, no-show, next call.
    }

    public function test_starting_service_cancels_timeout_and_service_never_ends_automatically(): void
    {
        $first = $this->book();
        $next = $this->book();
        $this->callFirst();
        app(QueueService::class)->transition($first, 'start', $this->agent);
        $this->travel(2)->hours();
        $this->assertFalse(app(AutomaticQueueService::class)->expire($first->id));
        $this->assertSame('serving', $first->fresh()->status->value);
        $this->assertSame('waiting', $next->fresh()->status->value);
    }

    public function test_completion_immediately_calls_next_and_command_replay_does_not_advance_twice(): void
    {
        $first = $this->book();
        $next = $this->book();
        $last = $this->book();
        $this->callFirst();
        $queue = app(QueueService::class);
        $queue->transition($first, 'start', $this->agent);
        $desk = app(ReceptionDeskService::class);
        $key = (string) Str::uuid();
        $desk->command($this->agent, $key, 'complete', $first->id);
        $desk->command($this->agent, $key, 'complete', $first->id);
        $this->assertSame('completed', $first->fresh()->status->value);
        $this->assertSame('called', $next->fresh()->status->value);
        $this->assertSame('waiting', $last->fresh()->status->value);
    }

    public function test_manual_mode_neither_times_out_nor_calls_next_after_completion(): void
    {
        $this->branch->update(['automatic_queue' => false]);
        $first = $this->book();
        $next = $this->book();
        $this->callFirst();
        $this->travel(60)->seconds();
        $this->assertFalse(app(AutomaticQueueService::class)->expire($first->id));
        $queue = app(QueueService::class);
        $queue->transition($first, 'start', $this->agent);
        $queue->transition($first, 'complete', $this->agent);
        $this->assertSame('waiting', $next->fresh()->status->value);
        $this->assertNull($this->counter->currentTicket());
    }

    public function test_recall_resets_deadline_and_old_delayed_job_cannot_skip_customer(): void
    {
        $first = $this->book();
        $next = $this->book();
        $this->callFirst();
        $this->travel(20)->seconds();
        app(QueueService::class)->transition($first, 'recall', $this->agent);
        $this->travel(10)->seconds();
        (new HandleCallTimeout($first->id))->handle(app(AutomaticQueueService::class));
        $this->assertSame('called', $first->fresh()->status->value);
        $this->travel(20)->seconds();
        $this->assertTrue(app(AutomaticQueueService::class)->expire($first->id));
        $this->assertSame('called', $next->fresh()->status->value);
    }

    public function test_paused_counter_waits_until_resumed_and_disabled_account_is_not_automated(): void
    {
        $first = $this->book();
        $this->book();
        $this->callFirst();
        $this->counter->update(['is_paused' => true]);
        $this->travel(31)->seconds();
        $automatic = app(AutomaticQueueService::class);
        $this->assertFalse($automatic->expire($first->id));
        $this->counter->update(['is_paused' => false]);
        $this->agent->update(['is_active' => false]);
        $this->assertFalse($automatic->expire($first->id));
        $this->agent->update(['is_active' => true]);
        $this->assertTrue($automatic->expire($first->id));
    }

    public function test_disabled_mode_and_closed_branch_cancel_pending_timeout_actions(): void
    {
        $first = $this->book();
        $this->callFirst();
        $this->travel(31)->seconds();
        $this->branch->update(['automatic_queue' => false]);
        $automatic = app(AutomaticQueueService::class);
        $this->assertFalse($automatic->expire($first->id));
        $this->branch->update(['automatic_queue' => true, 'is_open' => false]);
        $this->assertFalse($automatic->expire($first->id));
        $this->assertSame('called', $first->fresh()->status->value);
    }

    public function test_scheduled_recovery_handles_missed_job_and_empty_queue(): void
    {
        $first = $this->book();
        $this->callFirst();
        $this->travel(30)->seconds();
        $this->artisan('queue:advance-expired')->assertSuccessful();
        $this->assertSame('no_show', $first->fresh()->status->value);
        $this->assertNull($this->counter->currentTicket());
        $this->assertSame(0, app(AutomaticQueueService::class)->sweep());
    }

    public function test_changed_timeout_applies_to_next_call_and_does_not_shorten_current_deadline(): void
    {
        $first = $this->book();
        $this->book();
        $this->branch->update(['no_show_after_seconds' => 60]);
        $this->callFirst();
        $this->branch->update(['no_show_after_seconds' => 30]);
        $this->travel(30)->seconds();
        $automatic = app(AutomaticQueueService::class);
        $this->assertFalse($automatic->expire($first->id));
        $this->travel(30)->seconds();
        $this->assertTrue($automatic->expire($first->id));
        $this->assertEquals(30, now()->diffInSeconds($this->counter->currentTicket()->callDeadline()));
    }

    public function test_only_system_admin_can_change_automation_and_timeout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $page = Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)
            ->set('automaticQueue', false)->set('noShowSeconds', 120)->call('saveAutomation')->assertHasNoErrors();
        $this->assertFalse($this->branch->fresh()->automatic_queue);
        $this->assertSame(120, $this->branch->fresh()->no_show_after_seconds);
        $page->set('noShowSeconds', 0)->call('saveAutomation')->assertHasErrors('noShowSeconds');
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->call('saveAutomation')->assertForbidden();
        app(OperationsService::class)->saveSettings($manager, $this->branch->id, ['opensAt' => '09:00', 'closesAt' => '17:00', 'noShowSeconds' => 30, 'automaticQueue' => true]);
        $this->assertFalse($this->branch->fresh()->automatic_queue);
        $this->assertSame(120, $this->branch->fresh()->no_show_after_seconds);
    }
}
