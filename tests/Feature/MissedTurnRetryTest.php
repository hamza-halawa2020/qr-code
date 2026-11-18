<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Filament\Pages\Operations;
use App\Jobs\HandleCallTimeout;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomaticQueueService;
use App\Services\CloseBusinessDay;
use App\Services\QueueService;
use App\Services\QueueSnapshot;
use App\Services\ReceptionDeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class MissedTurnRetryTest extends TestCase
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
        config(['firebase.enabled' => false]);
        Queue::fake([HandleCallTimeout::class]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Retries', 'timezone' => 'UTC', 'automatic_queue' => true, 'no_show_after_seconds' => 30, 'missed_turn_retries' => 2]);
        $this->serviceId = $this->branch->services()->create(['name' => 'Service'])->id;
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->agent->services()->sync([$this->serviceId]);
        $this->counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    private function book(?int $service = null): Ticket
    {
        return app(QueueService::class)->book($this->branch, $s = Str::random(64), $s, ['service_id' => $service ?? $this->serviceId]);
    }

    private function expire(Ticket $ticket): void
    {
        $this->travel(30)->seconds();
        $this->assertTrue(app(AutomaticQueueService::class)->expire($ticket->id));
    }

    private function finish(Ticket $ticket, ?User $user = null): void
    {
        $q = app(QueueService::class);
        $q->transition($ticket, 'start', $user ?? $this->agent);
        $q->transition($ticket, 'complete', $user ?? $this->agent);
    }

    public function test_number_five_returns_after_six_then_seven_until_extra_attempts_are_exhausted(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->book()->update(['status' => 'completed', 'finished_at' => now()]);
        }
        $five = $this->book();
        $six = $this->book();
        $seven = $this->book();
        $eight = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($five);
        $this->assertSame(TicketStatus::Deferred, $five->fresh()->status);
        $this->assertNull($five->fresh()->finished_at);
        $this->assertNull($five->fresh()->counter_id);
        $this->assertSame($six->id, $this->counter->currentTicket()->id);
        $this->finish($six);
        $this->assertSame($five->id, $this->counter->currentTicket()->id);
        $this->assertSame(1, (int) $five->fresh()->retry_attempts);
        $this->expire($five);
        $this->assertSame($seven->id, $this->counter->currentTicket()->id);
        $this->finish($seven);
        $this->assertSame($five->id, $this->counter->currentTicket()->id);
        $this->assertSame(2, (int) $five->fresh()->retry_attempts);
        $this->expire($five);
        $this->assertSame(TicketStatus::NoShow, $five->fresh()->status);
        $this->assertNotNull($five->fresh()->finished_at);
        $this->assertSame($eight->id, $this->counter->currentTicket()->id);
        $this->assertSame(2, DB::table('ticket_events')->where('ticket_id', $five->id)->where('type', 'retry_called')->count());
    }

    public function test_no_immediate_retry_without_a_completed_customer_and_pending_ticket_is_active(): void
    {
        $ticket = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($ticket);
        $this->assertNull($this->counter->currentTicket());
        $this->assertNull(app(QueueService::class)->next($this->counter, $this->agent));
        $this->assertTrue($ticket->fresh()->status->isActive());
        $snapshot = app(QueueSnapshot::class)->ticket($ticket->fresh());
        $this->assertSame('deferred', $snapshot['status']);
        $this->assertNull($snapshot['counter']);
        $this->assertNull($snapshot['ahead']);
        $this->assertStringContainsString('عميل آخر', $snapshot['description']);
        app(QueueService::class)->transition($ticket, 'cancel');
        $this->assertSame(TicketStatus::Cancelled, $ticket->fresh()->status);
    }

    public function test_manual_mode_requires_next_and_replayed_completion_does_not_release_twice(): void
    {
        $this->branch->update(['automatic_queue' => false]);
        $first = $this->book();
        $second = $this->book();
        $q = app(QueueService::class);
        $q->next($this->counter, $this->agent);
        $this->travel(30)->seconds();
        $q->transition($first, 'absent', $this->agent);
        $this->assertNull($this->counter->currentTicket());
        $q->next($this->counter, $this->agent);
        $q->transition($second, 'start', $this->agent);
        $key = (string) Str::uuid();
        $desk = app(ReceptionDeskService::class);
        $desk->command($this->agent, $key, 'complete', $second->id);
        $desk->command($this->agent, $key, 'complete', $second->id);
        $this->assertNull($this->counter->currentTicket());
        $this->assertSame($first->id, $q->next($this->counter, $this->agent)->id);
        $this->assertSame(1, DB::table('ticket_events')->where('ticket_id', $first->id)->where('type', 'retry_ready')->count());
    }

    public function test_other_service_completion_does_not_release_deferred_customer(): void
    {
        $first = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($first);
        $otherService = $this->branch->services()->create(['name' => 'Other']);
        $other = $this->book($otherService->id);
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $agent->services()->sync([$otherService->id]);
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Other', 'number' => 2, 'user_id' => $agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        app(QueueService::class)->next($counter, $agent);
        $this->finish($other, $agent);
        $this->assertSame(TicketStatus::Deferred, $first->fresh()->status);
    }

    public function test_successful_retry_stops_further_calls_and_due_appointments_keep_priority(): void
    {
        $first = $this->book();
        $second = $this->book();
        $appointment = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($first);
        $appointment->update(['appointment_at' => now()]);
        $this->finish($second);
        $this->assertSame($appointment->id, $this->counter->currentTicket()->id);
        $this->assertSame(0, (int) $first->fresh()->retry_attempts);
        $this->finish($appointment);
        $this->assertSame($first->id, $this->counter->currentTicket()->id);
        $this->finish($first);
        $this->assertSame(TicketStatus::Completed, $first->fresh()->status);
        $this->assertNull($this->counter->currentTicket());
    }

    public function test_one_completion_releases_only_one_of_multiple_deferred_customers(): void
    {
        $this->branch->update(['automatic_queue' => false]);
        $first = $this->book();
        $second = $this->book();
        $third = $this->book();
        $q = app(QueueService::class);
        $q->next($this->counter, $this->agent);
        $this->travel(30)->seconds();
        $q->transition($first, 'absent', $this->agent);
        $q->next($this->counter, $this->agent);
        $this->travel(30)->seconds();
        $q->transition($second, 'absent', $this->agent);
        $q->next($this->counter, $this->agent);
        $this->finish($third);
        $this->assertSame(TicketStatus::Waiting, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->retry_ready_at);
        $this->assertSame(TicketStatus::Deferred, $second->fresh()->status);
        $this->assertSame($first->id, $q->next($this->counter, $this->agent)->id);
    }

    public function test_limit_is_fixed_at_first_miss_and_close_day_expires_deferred_tickets(): void
    {
        $first = $this->book();
        $second = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($first);
        $this->branch->update(['missed_turn_retries' => 0]);
        $this->finish($second);
        $this->expire($first);
        $this->assertSame(2, (int) $first->fresh()->retry_limit);
        $this->assertSame(TicketStatus::Deferred, $first->fresh()->status);
        $this->travelTo(now()->setTime(18, 0));
        app(CloseBusinessDay::class)->run($this->branch);
        $this->assertSame(TicketStatus::Expired, $first->fresh()->status);
        $this->assertNotNull($first->fresh()->finished_at);
    }

    public function test_only_admin_can_configure_retry_limit_and_zero_disables_it(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);
        $page = Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)->set('missedTurnRetries', 11)->call('saveAutomation')->assertHasErrors('missedTurnRetries');
        $page->set('missedTurnRetries', 0)->call('saveAutomation')->assertHasNoErrors();
        $this->assertSame(0, (int) $this->branch->fresh()->missed_turn_retries);
        $ticket = $this->book();
        app(QueueService::class)->next($this->counter, $this->agent);
        $this->expire($ticket);
        $this->assertSame(TicketStatus::NoShow, $ticket->fresh()->status);
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->set('missedTurnRetries',3)->call('saveAutomation')->assertForbidden();
    }
}
