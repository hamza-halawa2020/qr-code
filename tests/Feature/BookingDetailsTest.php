<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperationsService;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class BookingDetailsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private int $serviceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $this->serviceId = $this->branch->services()->create(['name' => 'استعلام'])->id;
    }

    public function test_booking_requires_phone_and_an_active_service_in_the_same_branch(): void
    {
        $url = route('branch.book', $this->branch);
        $this->postJson($url)->assertUnprocessable()->assertJsonValidationErrors(['service_id', 'customer_phone']);
        $other = Branch::create(['name' => 'Other']);
        $foreign = $other->services()->create(['name' => 'Foreign']);
        $this->postJson($url, ['service_id' => $foreign->id, 'customer_phone' => '01012345678'])->assertJsonValidationErrors('service_id');
        $this->branch->services()->findOrFail($this->serviceId)->update(['is_active' => false]);
        $this->postJson($url, ['service_id' => $this->serviceId, 'customer_phone' => '01012345678'])->assertJsonValidationErrors('service_id');
        $this->branch->services()->findOrFail($this->serviceId)->update(['is_active' => true]);
        $this->postJson($url, ['service_id' => $this->serviceId, 'customer_phone' => '01234'])->assertJsonValidationErrors('customer_phone');
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_phone_is_normalized_encrypted_and_excluded_from_public_snapshots(): void
    {
        $response = $this->post(route('branch.book', $this->branch), ['service_id' => $this->serviceId, 'customer_phone' => '٠١٠ ١٢٣٤٥٦٧٨']);
        $response->assertRedirect();
        $ticket = Ticket::firstOrFail();
        $this->assertSame('01012345678', $ticket->customer_phone);
        $this->assertSame('استعلام', $ticket->service_name);
        $this->assertNotSame('01012345678', DB::table('tickets')->value('customer_phone'));
        $this->assertArrayNotHasKey('customer_phone', $ticket->toArray());
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('استعلام')->assertDontSee('01012345678');
        $secret = basename($response->headers->get('Location'));
        $this->getJson(route('ticket.state', $secret))->assertOk()->assertDontSee('01012345678')->assertJsonMissingPath('customer_phone');
        $this->post(route('branch.book', $this->branch))->assertRedirect($response->headers->get('Location'));
        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_international_phone_and_historical_service_name_are_preserved(): void
    {
        $this->post(route('branch.book', $this->branch), ['service_id' => $this->serviceId, 'customer_phone' => '00966 50 123 4567'])->assertRedirect();
        $this->branch->services()->findOrFail($this->serviceId)->update(['name' => 'اسم جديد', 'is_active' => false]);
        $ticket = Ticket::firstOrFail();
        $this->assertSame('+966501234567', $ticket->customer_phone);
        $this->assertSame('استعلام', $ticket->service_name);
    }

    public function test_different_services_keep_one_fifo_queue_and_staff_can_see_details(): void
    {
        $other = $this->branch->services()->create(['name' => 'طلب جديد']);
        $queue = app(QueueService::class);
        $first = $queue->book($this->branch, $a = Str::random(64), $a, ['service_id' => $this->serviceId, 'customer_phone' => '01012345678']);
        $second = $queue->book($this->branch, $b = Str::random(64), $b, ['service_id' => $other->id, 'customer_phone' => '01112345678']);
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $displaySecret = Str::random(64);
        $counter = Counter::create(['branch_id' => $this->branch->id, 'number' => 1, 'name' => 'Window', 'user_id' => $agent->id, 'display_token_hash' => hash('sha256', $displaySecret)]);
        $agent->services()->sync([$this->serviceId, $other->id]);
        $this->assertSame(0, $second->ahead());
        $this->assertSame($first->id, $queue->next($counter, $agent)->id);
        $this->actingAs($agent)->get('/admin/reception-desk')->assertOk()->assertSee('استعلام')->assertSee('01012345678');
        $this->getJson(route('display.state', [$counter, $displaySecret]))->assertOk()->assertDontSee('01012345678')->assertDontSee('customer_phone');
        $queue->transition($first, 'start', $agent);
        $queue->transition($first, 'complete', $agent);
        $this->assertSame($second->id, $queue->next($counter, $agent)->id);
    }

    public function test_service_management_is_scoped_and_empty_service_list_blocks_customer_form(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $other = Branch::create(['name' => 'Other']);
        $foreign = $other->services()->create(['name' => 'Foreign']);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)->set('serviceName', 'طلب جديد')->call('createService')->assertHasNoErrors();
        $this->assertDatabaseHas('branch_services', ['branch_id' => $this->branch->id, 'name' => 'طلب جديد']);
        Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)->call('toggleService', $foreign->id)->assertNotFound();
        $this->branch->services()->update(['is_active' => false]);
        $this->get(route('branch.show', $this->branch))->assertOk()->assertSee('لا توجد خدمات متاحة')->assertDontSee('name="customer_phone"', false);
    }

    public function test_new_branch_gets_a_default_service(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $branch = app(OperationsService::class)->createBranch($admin, ['branchName' => 'New', 'opensAt' => '09:00', 'closesAt' => '17:00']);
        $this->assertSame('خدمة عامة', $branch->services()->firstOrFail()->name);
    }
}
