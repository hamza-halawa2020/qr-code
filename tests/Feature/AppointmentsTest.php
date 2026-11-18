<?php

namespace Tests\Feature;

use App\Filament\Pages\Appointments;
use App\Models\AppointmentSlot;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\User;
use App\Services\AppointmentService;
use App\Services\QueueService;
use App\Services\QueueSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AppointmentsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Appointments', 'timezone' => 'UTC']);
        $this->serviceId = $this->branch->services()->create(['name' => 'Service'])->id;
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function appointmentSession(): Store
    {
        $s = new Store('appointment-test', new ArraySessionHandler(120));
        $s->start();

        return $s;
    }

    private function slot(): AppointmentSlot
    {
        return AppointmentSlot::create(['branch_id' => $this->branch->id, 'branch_service_id' => $this->serviceId, 'starts_at' => now()->addHour(), 'capacity' => 1]);
    }

    private function book(AppointmentSlot $slot, ?Store $session = null): string
    {
        return app(AppointmentService::class)->book($this->branch, $session ?? $this->appointmentSession(), ['slot_id' => $slot->id, 'customer_name' => 'اسم العميل', 'customer_phone' => '٠١٠١٢٣٤٥٦٧٨', 'customer_email' => 'client@example.test']);
    }

    public function test_capacity_and_idempotency_are_enforced_and_customer_data_is_encrypted(): void
    {
        $slot = $this->slot();
        $session = $this->appointmentSession();
        $secret = $this->book($slot, $session);
        $this->assertSame($secret, $this->book($slot, $session));
        $this->assertDatabaseCount('appointments', 1);
        $appointment = app(AppointmentService::class)->find($secret);
        $this->assertSame('01012345678', $appointment->customer_phone);
        $this->assertNotSame('01012345678', DB::table('appointments')->value('customer_phone'));
        $this->assertNotSame('اسم العميل', DB::table('appointments')->value('customer_name'));
        $this->get(route('appointment.show', $secret))->assertOk()->assertSee('تفاصيل موعدك')->assertDontSee('client@example.test');
        $this->expectException(ValidationException::class);
        $this->book($slot);
    }

    public function test_cancel_releases_slot_and_same_browser_can_rebook(): void
    {
        $slot = $this->slot();
        $session = $this->appointmentSession();
        $secret = $this->book($slot, $session);
        app(AppointmentService::class)->cancel($secret);
        $replacement = $this->book($slot, $session);
        $this->assertNotSame($secret, $replacement);
        $this->assertDatabaseCount('appointments', 2);
        $this->assertSame('cancelled', app(AppointmentService::class)->find($secret)->status);
    }

    public function test_check_in_window_priority_at_due_time_and_no_interruption_of_started_service(): void
    {
        $slot = $this->slot();
        $secret = $this->book($slot);
        $service = app(AppointmentService::class);
        try {
            $service->checkIn($secret, $this->appointmentSession());
            $this->fail('Too early');
        } catch (ValidationException) {
        }
        $queue = app(QueueService::class);
        $regular = $queue->book($this->branch, $s = Str::random(64), $s, ['service_id' => $this->serviceId]);
        $this->travel(45)->minutes();
        $session = $this->appointmentSession();
        $service->checkIn($secret, $session);
        $service->checkIn($secret, $session);
        $appointment = $service->find($secret);
        $ticket = $appointment->ticket;
        $last = $queue->book($this->branch, $s = Str::random(64), $s, ['service_id' => $this->serviceId]);
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $agent->services()->sync([$this->serviceId]);
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $this->assertFalse(app(QueueSnapshot::class)->ticket($ticket)['near_turn']);
        $this->assertSame($regular->id, $queue->next($counter, $agent)->id);
        $queue->transition($regular, 'start', $agent);
        $this->travel(15)->minutes();
        $this->assertSame('serving', $regular->fresh()->status->value);
        $queue->transition($regular, 'complete', $agent);
        $this->assertSame(1, $last->ahead());
        $this->assertSame($ticket->id, $queue->next($counter, $agent)->id);
        $this->assertDatabaseCount('tickets', 3);
        $this->assertSame('اسم العميل', $ticket->customer_name);
    }

    public function test_unconfirmed_or_late_appointments_never_occupy_queue(): void
    {
        $slot = $this->slot();
        $secret = $this->book($slot);
        $this->assertDatabaseCount('tickets', 0);
        $this->travel(91)->minutes();
        try {
            app(AppointmentService::class)->checkIn($secret, $this->appointmentSession());
            $this->fail('Late arrival');
        } catch (ValidationException) {
        }
        $this->artisan('appointments:expire')->assertSuccessful();
        $this->assertSame('expired', app(AppointmentService::class)->find($secret)->status);
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_admin_creates_slots_and_manager_cannot_create_or_access_other_branch_data(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Appointments::class)->set('branchId', $this->branch->id)->set('serviceId', $this->serviceId)->set('date', now()->addDay()->toDateString())->set('time', '11:00')->set('capacity', 2)->call('createSlot')->assertHasNoErrors();
        $this->assertDatabaseCount('appointment_slots', 1);
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $other = Branch::create(['name' => 'Other']);
        $this->actingAs($manager);
        Livewire::test(Appointments::class)->set('serviceId', $this->serviceId)->call('createSlot')->assertForbidden();
        $data = app(AppointmentService::class)->adminData($manager, $other->id);
        $this->assertNull($data['branch']);
        $this->assertCount(0, $data['appointments']);
    }

    public function test_slots_and_booking_reject_wrong_branch_or_invalid_details(): void
    {
        $slot = $this->slot();
        $other = Branch::create(['name' => 'Other']);
        $this->get(route('appointments.index', $this->branch))->assertOk()->assertSee('11:00');
        $this->get(route('appointment.show', Str::random(64)))->assertNotFound();
        $this->postJson(route('appointments.book', $this->branch), ['slot_id' => $slot->id])->assertJsonValidationErrors(['customer_name', 'customer_phone']);
        $this->postJson(route('appointments.book', $other), ['slot_id' => $slot->id, 'customer_name' => 'Name', 'customer_phone' => '01012345678'])->assertNotFound();
    }

    public function test_existing_reservation_is_honored_after_new_bookings_for_service_are_disabled(): void
    {
        $slot = $this->slot();
        $secret = $this->book($slot);
        $this->branch->services()->whereKey($this->serviceId)->update(['is_active' => false]);
        $this->travel(60)->minutes();
        app(AppointmentService::class)->checkIn($secret, $this->appointmentSession());
        $this->assertSame('checked_in', app(AppointmentService::class)->find($secret)->status);
    }

    public function test_state_polling_does_not_consume_appointment_booking_limit(): void
    {
        $slot = $this->slot();
        app(QueueService::class)->book($this->branch, $secret = Str::random(64), $secret);
        for ($index = 0; $index < 15; $index++) {
            $this->getJson(route('ticket.state', $secret))->assertOk();
        }
        $this->post(route('appointments.book', $this->branch), ['slot_id' => $slot->id, 'customer_name' => 'Client', 'customer_phone' => '01012345678'])->assertRedirect();
        $this->assertDatabaseCount('appointments', 1);
    }
}
