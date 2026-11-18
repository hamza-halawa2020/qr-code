<?php

namespace Tests\Feature;

use App\Jobs\PublishBranch;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\FirebaseGateway;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class QueueControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Ticket $ticket;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake([PublishBranch::class]);
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $this->secret = Str::random(64);
        $this->ticket = app(QueueService::class)->book($this->branch, $this->secret, $this->secret);
    }

    public function test_qr_download_retains_branch_authorization(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $other = Branch::create(['name' => 'Other']);
        $this->actingAs($manager)->get(route('branch.qr', $this->branch))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSee('<svg', false);
        $this->get(route('branch.qr', $other))->assertForbidden();
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->actingAs($agent)->get(route('branch.qr', $this->branch))->assertForbidden();
    }

    public function test_customer_can_cancel_waiting_ticket_but_not_cancel_it_again(): void
    {
        $this->post(route('ticket.cancel', $this->secret))->assertRedirect(route('ticket.show', $this->secret));
        $this->getJson(route('ticket.state', $this->secret))->assertOk()->assertJsonPath('status', 'cancelled');
        $this->get(route('ticket.show', $this->secret))->assertOk()->assertSee('تم إلغاء التذكرة');
        $this->postJson(route('ticket.cancel', $this->secret))->assertUnprocessable();
    }

    public function test_display_requires_its_secret_for_page_and_state(): void
    {
        $secret = Str::random(64);
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window 1', 'number' => 1, 'display_token_hash' => hash('sha256', $secret)]);
        $this->get(route('display.show', [$counter, $secret]))->assertOk()->assertSee('Window 1');
        $this->getJson(route('display.state', [$counter, $secret]))->assertOk()->assertJsonPath('number', null);
        $this->getJson(route('display.state', [$counter, Str::random(64)]))->assertNotFound();
        $counter->update(['display_token_hash' => hash('sha256', Str::random(64))]);
        $this->get(route('display.show', [$counter, $secret]))->assertNotFound();
    }

    public function test_disabled_firebase_endpoints_keep_service_unavailable_response(): void
    {
        $this->postJson(route('ticket.firebase', $this->secret))->assertStatus(503);
        $this->postJson(route('ticket.subscribe', $this->secret), ['token' => 'test-token'])->assertStatus(503);
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_closed_branch_shows_closed_state_and_booking_is_rate_limited(): void
    {
        $this->branch->update(['is_open' => false]);
        $this->get(route('branch.show', $this->branch))->assertOk()->assertSee('الحجز مغلق');
        $this->branch->update(['is_open' => true]);
        $service = $this->branch->services()->create(['name' => 'استعلام']);
        for ($i = 0; $i < 10; $i++) {
            $response = $this->post(route('branch.book', $this->branch), ['service_id' => $service->id, 'customer_phone' => '01012345678'])->assertRedirect();
            $cookie = $response->getCookie(config('session.cookie'), false);
            $this->withUnencryptedCookie(config('session.cookie'), $cookie->getValue());
        }
        $this->withCredentials()->postJson(route('branch.book', $this->branch))->assertStatus(429);
    }

    public function test_firebase_credentials_use_only_the_authorized_ticket_or_counter_scope(): void
    {
        config(['firebase.enabled' => true]);
        $counterSecret = Str::random(64);
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window 1', 'number' => 1, 'display_token_hash' => hash('sha256', $counterSecret)]);
        $gateway = $this->mock(FirebaseGateway::class);
        $gateway->shouldReceive('customToken')->once()->with('ticket-'.$this->ticket->public_id, ['ticket_id' => $this->ticket->public_id])->andReturn('ticket-custom-token');
        $gateway->shouldReceive('customToken')->once()->with('counter-'.$counter->id, ['counter_id' => (string) $counter->id])->andReturn('counter-custom-token');
        $this->postJson(route('ticket.firebase', $this->secret))->assertOk()
            ->assertJsonPath('token', 'ticket-custom-token')->assertJsonPath('path', 'tickets/'.$this->ticket->public_id);
        $this->postJson(route('display.firebase', [$counter, $counterSecret]))->assertOk()
            ->assertJsonPath('token', 'counter-custom-token')->assertJsonPath('path', 'counters/'.$counter->id);
        $this->postJson(route('ticket.firebase', Str::random(64)))->assertNotFound();
        $this->postJson(route('display.firebase', [$counter, Str::random(64)]))->assertNotFound();
    }

    public function test_subscriptions_validate_input_and_upsert_without_duplicates(): void
    {
        config(['firebase.enabled' => true]);
        $url = route('ticket.subscribe', $this->secret);
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson($url, ['token' => ['invalid']])->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->postJson($url, ['token' => 'test-device-token'])->assertOk()->assertJsonPath('ok', true);
        $this->postJson($url, ['token' => 'test-device-token'])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', ['ticket_id' => $this->ticket->id, 'token_hash' => hash('sha256', 'test-device-token')]);
        $this->ticket->update(['status' => 'completed']);
        $this->postJson($url, ['token' => 'other-device-token'])->assertUnprocessable();
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_unsubscribe_only_removes_matching_ticket_and_device_subscription(): void
    {
        $otherSecret = Str::random(64);
        $other = app(QueueService::class)->book($this->branch, $otherSecret, $otherSecret);
        config(['firebase.enabled' => true]);
        $this->postJson(route('ticket.subscribe', $this->secret), ['token' => 'first-device'])->assertOk();
        $this->postJson(route('ticket.subscribe', $otherSecret), ['token' => 'other-device'])->assertOk();
        $this->postJson(route('ticket.unsubscribe', $this->secret), ['token' => 'other-device'])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 2);
        $this->postJson(route('ticket.unsubscribe', $this->secret), ['token' => 'first-device'])->assertOk();
        $this->postJson(route('ticket.unsubscribe', $this->secret), ['token' => 'first-device'])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 1);
        $this->assertDatabaseHas('push_subscriptions', ['ticket_id' => $other->id, 'token_hash' => hash('sha256', 'other-device')]);
    }

    public function test_unsubscribe_remains_available_after_completion_or_disabling_firebase(): void
    {
        config(['firebase.enabled' => true]);
        $this->postJson(route('ticket.subscribe', $this->secret), ['token' => 'device-token'])->assertOk();
        $this->ticket->update(['status' => 'completed']);
        config(['firebase.enabled' => false]);
        $this->postJson(route('ticket.unsubscribe', $this->secret), ['token' => 'device-token'])->assertOk();
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->postJson(route('ticket.unsubscribe', Str::random(64)), ['token' => 'device-token'])->assertNotFound();
        $this->postJson(route('ticket.unsubscribe', $this->secret), [])->assertUnprocessable()->assertJsonValidationErrors('token');
    }
}
