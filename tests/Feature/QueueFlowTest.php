<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\OutboxMessage;
use App\Models\Ticket;
use App\Models\User;
use App\Services\CloseBusinessDay;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class QueueFlowTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private QueueService $queue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 17)->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test branch', 'timezone' => 'UTC', 'opens_at' => '09:00', 'closes_at' => '17:00']);
        $this->queue = app(QueueService::class);
        $this->withoutVite();
    }

    private function book(): Ticket
    {
        $secret = Str::random(64);

        return $this->queue->book($this->branch, $secret, $secret);
    }

    private function counter(int $number = 1): Counter
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id, 'role' => 'agent']);

        return Counter::create(['branch_id' => $this->branch->id, 'name' => 'Counter '.$number, 'number' => $number, 'user_id' => $user->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    public function test_booking_retry_returns_same_ticket_and_sequence_remains_unique(): void
    {
        $secret = Str::random(64);
        $first = $this->queue->book($this->branch, $secret, $secret);
        $retry = $this->queue->book($this->branch, $secret, $secret);
        $second = $this->book();
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(2, $second->number);
        $this->assertSame(1, $second->ahead());
        $this->assertSame(2, OutboxMessage::count());
    }

    public function test_two_employees_take_different_customers_and_service_completes(): void
    {
        $first = $this->book();
        $second = $this->book();
        $third = $this->book();
        $a = $this->counter();
        $b = $this->counter(2);
        $this->assertSame($first->id, $this->queue->next($a, $a->user)->id);
        $this->assertSame($second->id, $this->queue->next($b, $b->user)->id);
        $this->assertSame(0, $third->ahead());
        $this->queue->transition($first, 'start', $a->user);
        $this->travel(5)->minutes();
        $completed = $this->queue->transition($first, 'complete', $a->user);
        $this->assertSame(TicketStatus::Completed, $completed->status);
        $this->assertNotNull($completed->started_at);
        $this->assertNotNull($completed->finished_at);
    }

    public function test_double_next_cannot_replace_current_customer(): void
    {
        $this->book();
        $this->book();
        $counter = $this->counter();
        $this->queue->next($counter, $counter->user);
        try {
            $this->queue->next($counter, $counter->user);
            $this->fail('Double next must fail');
        } catch (ValidationException) {
            $this->assertSame(1, Ticket::where('status', 'waiting')->count());
        }
    }

    public function test_cancellation_and_no_show_remove_customers_from_waiting_count(): void
    {
        $first = $this->book();
        $second = $this->book();
        $third = $this->book();
        $this->queue->transition($second, 'cancel');
        $this->assertSame(1, $third->ahead());
        $counter = $this->counter();
        $this->queue->next($counter, $counter->user);
        $this->travel(61)->seconds();
        $this->queue->transition($first, 'absent', $counter->user);
        $this->assertSame(0, $third->ahead());
    }

    public function test_invalid_transition_is_rejected(): void
    {
        $ticket = $this->book();
        $this->expectException(ValidationException::class);
        $this->queue->transition($ticket, 'cancel');
        $this->queue->transition($ticket, 'cancel');
    }

    public function test_branch_closing_expires_waiting_but_preserves_started_service(): void
    {
        $first = $this->book();
        $waiting = $this->book();
        $counter = $this->counter();
        $this->queue->next($counter, $counter->user);
        $this->queue->transition($first, 'start', $counter->user);
        $this->travelTo(now()->setTime(18, 0));
        $this->assertSame(1, app(CloseBusinessDay::class)->run($this->branch));
        $this->assertSame(TicketStatus::Expired, $waiting->fresh()->status);
        $this->assertSame(TicketStatus::Serving, $first->fresh()->status);
    }

    public function test_closed_branch_rejects_booking(): void
    {
        $this->branch->update(['is_open' => false]);
        $this->expectException(ValidationException::class);
        $this->book();
    }

    public function test_branch_numbering_and_calls_do_not_mix_queues(): void
    {
        $first = $this->book();
        $otherBranch = Branch::create(['name' => 'Other branch', 'timezone' => 'UTC']);
        $secret = Str::random(64);
        $otherTicket = $this->queue->book($otherBranch, $secret, $secret);
        $this->assertSame(1, $first->number);
        $this->assertSame(1, $otherTicket->number);
        $counter = $this->counter();
        $this->assertSame($first->id, $this->queue->next($counter, $counter->user)->id);
        $this->assertSame('waiting', $otherTicket->fresh()->status->value);
    }

    public function test_no_show_is_rejected_before_configured_grace_period(): void
    {
        $ticket = $this->book();
        $counter = $this->counter();
        $this->queue->next($counter, $counter->user);
        try {
            $this->queue->transition($ticket, 'absent', $counter->user);
            $this->fail('Grace period must be enforced');
        } catch (ValidationException) {
            $this->assertSame(TicketStatus::Called, $ticket->fresh()->status);
        }
    }

    public function test_next_day_restarts_numbers_without_changing_old_ticket(): void
    {
        $yesterday = $this->book();
        $this->travel(1)->days();
        app(CloseBusinessDay::class)->run($this->branch);
        $today = $this->book();
        $this->assertSame(1, $today->number);
        $this->assertSame(TicketStatus::Expired, $yesterday->fresh()->status);
        $this->assertNotEquals($yesterday->business_date->toDateString(), $today->business_date->toDateString());
        $this->assertSame(2, Ticket::count());
    }

    public function test_http_booking_retry_uses_existing_active_ticket(): void
    {
        $service = $this->branch->services()->create(['name' => 'استعلام']);
        $response = $this->post(route('branch.book', $this->branch), ['service_id' => $service->id, 'customer_phone' => '01012345678']);
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->post(route('branch.book', $this->branch))->assertRedirect($location);
        $this->get(route('branch.show', $this->branch))->assertOk()->assertSee('متابعة دوري');
        $this->assertSame(1, Ticket::count());
    }

    public function test_public_pages_protect_ticket_secrets_and_display_secrets(): void
    {
        $secret = Str::random(64);
        $ticket = $this->queue->book($this->branch, $secret, $secret);
        $this->get('/')->assertOk();
        $this->get(route('ticket.show', $secret))->assertOk()->assertSee('001');
        $this->get(route('ticket.state', $secret))->assertOk()->assertJsonPath('ahead', 0)->assertJsonMissingPath('token_hash');
        $this->get('/t/'.Str::random(64))->assertNotFound();
        $this->get('/t/'.$ticket->public_id)->assertNotFound();
        $counter = $this->counter();
        $this->get(route('display.show', [$counter, Str::random(64)]))->assertNotFound();
    }

    public function test_other_employee_cannot_operate_counter(): void
    {
        $counter = $this->counter();
        $other = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->book();
        try {
            $this->queue->next($counter, $other);
            $this->fail('Unauthorized employee');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_employee_cannot_open_management_and_reports(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->actingAs($agent)->get('/admin/operations')->assertForbidden();
        $this->actingAs($agent)->get('/admin/reports')->assertForbidden();
        $this->actingAs($agent)->get('/admin/reception-desk')->assertOk();
    }
}
