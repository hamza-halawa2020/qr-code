<?php

namespace Tests\Feature;

use App\Filament\Pages\ReceptionDesk;
use App\Filament\Pages\Operations;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\EmployeeServiceService;
use App\Services\QueueService;
use App\Services\ReceptionDeskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeServiceRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $agent;
    private User $admin;
    private Counter $counter;
    private array $services;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $this->services = collect([1, 2, 3])->map(fn ($n) => $this->branch->services()->create(['name' => 'Service '.$n])->id)->all();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $this->counter = Counter::create(['branch_id' => $this->branch->id, 'number' => 1, 'name' => 'Window', 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
    }

    private function book(int $serviceId): Ticket
    {
        return app(QueueService::class)->book($this->branch, $secret = Str::random(64), $secret, ['service_id' => $serviceId, 'customer_phone' => '01012345678']);
    }

    public function test_next_skips_unsupported_service_and_calls_oldest_matching_customer(): void
    {
        $unsupported = $this->book($this->services[2]);
        $firstMatch = $this->book($this->services[1]);
        $this->book($this->services[0]);
        app(EmployeeServiceService::class)->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[0], $this->services[1]]);
        $ticket = app(QueueService::class)->next($this->counter, $this->agent);
        $this->assertSame($firstMatch->id, $ticket->id);
        $this->assertSame('waiting', $unsupported->fresh()->status->value);
        $this->assertNull($unsupported->fresh()->counter_id);
        $view = app(ReceptionDeskService::class)->viewData($this->agent);
        $this->assertSame([3], $view['waiting']->pluck('number')->all());
        $this->assertDatabaseMissing('outbox_messages', ['ticket_id' => $unsupported->id, 'type' => 'called']);
        $this->assertDatabaseHas('outbox_messages', ['ticket_id' => $firstMatch->id, 'type' => 'called']);
    }

    public function test_no_selected_services_or_no_match_never_calls_typed_ticket(): void
    {
        $ticket = $this->book($this->services[2]);
        $queue = app(QueueService::class);
        $this->assertNull($queue->next($this->counter, $this->agent));
        app(EmployeeServiceService::class)->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[0]]);
        $this->assertNull($queue->next($this->counter, $this->agent));
        $this->assertSame('waiting', $ticket->fresh()->status->value);
    }

    public function test_admin_can_assign_services_and_cannot_select_services_from_another_branch(): void
    {
        $other = Branch::create(['name' => 'Other']);
        $foreign = $other->services()->create(['name' => 'Foreign']);
        $this->actingAs($this->admin);
        $page = Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)->call('editEmployeeServices', $this->agent->id)->set('employeeServiceIds', [$this->services[0], $this->services[1]])->call('saveEmployeeServices')->assertHasNoErrors();
        $this->assertCount(2, $this->agent->services()->get());
        $page->call('editEmployeeServices', $this->agent->id)->set('employeeServiceIds', [$foreign->id])->call('saveEmployeeServices')->assertHasErrors('employeeServiceIds');
        $this->assertCount(2, $this->agent->services()->get());
        $page->call('editEmployeeServices', $this->agent->id)->set('employeeServiceIds', [$this->services[0], $this->services[0]])->call('saveEmployeeServices')->assertHasErrors('employeeServiceIds');
        $page->call('editEmployeeServices', $this->agent->id)->set('employeeServiceIds', [999999])->call('saveEmployeeServices')->assertHasErrors('employeeServiceIds');
        $page->call('editEmployeeServices', $this->agent->id)->set('employeeServiceIds', [])->call('saveEmployeeServices')->assertHasNoErrors();
        $this->assertCount(0, $this->agent->services()->get());
    }

    public function test_service_changes_do_not_replace_current_ticket_and_apply_to_next_call(): void
    {
        $first = $this->book($this->services[0]);
        $second = $this->book($this->services[1]);
        $capabilities = app(EmployeeServiceService::class);
        $queue = app(QueueService::class);
        $capabilities->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[0]]);
        $queue->next($this->counter, $this->agent);
        $capabilities->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[1]]);
        $this->assertSame($first->id, $this->counter->currentTicket()->id);
        $queue->transition($first, 'start', $this->agent);
        $queue->transition($first, 'complete', $this->agent);
        $this->assertSame($second->id, $queue->next($this->counter, $this->agent)->id);
    }

    public function test_disabled_service_existing_tickets_remain_serviceable(): void
    {
        $ticket = $this->book($this->services[0]);
        $this->branch->services()->findOrFail($this->services[0])->update(['is_active' => false]);
        app(EmployeeServiceService::class)->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[0]]);
        $this->assertSame($ticket->id, app(QueueService::class)->next($this->counter, $this->agent)->id);
    }

    public function test_ahead_counts_only_earlier_waiting_tickets_of_the_same_service(): void
    {
        $first = $this->book($this->services[0]);
        $this->book($this->services[1]);
        $third = $this->book($this->services[0]);
        $this->assertSame(1, $third->ahead());
        app(QueueService::class)->transition($first, 'cancel');
        $this->assertSame(0, $third->ahead());
    }

    public function test_disabled_admin_cannot_assign_services_with_stale_user_model(): void
    {
        User::whereKey($this->admin->id)->update(['is_active' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(EmployeeServiceService::class)->save($this->admin, $this->branch->id, $this->agent->id, [$this->services[0]]);
    }

    public function test_agent_and_branch_manager_cannot_assign_services_even_by_direct_action(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->call('editEmployeeServices', $this->agent->id)->assertForbidden();
        $this->assertFalse(method_exists(ReceptionDesk::class, 'saveServices'));
        foreach ([$this->agent, $manager] as $actor) {
            try {
                app(EmployeeServiceService::class)->save($actor, $this->branch->id, $this->agent->id, [$this->services[0]]);
                $this->fail('Only system admin may assign services.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertCount(0, $this->agent->services()->get());
        $this->actingAs($this->agent)->get('/admin/reception-desk')->assertOk()->assertDontSee('حفظ خدماتي')->assertSee('الخدمات المخصصة لك');
    }

    public function test_admin_can_assign_before_counter_allocation_but_cannot_mix_employee_branches(): void
    {
        $newEmployee = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        app(EmployeeServiceService::class)->save($this->admin, $this->branch->id, $newEmployee->id, [$this->services[1]]);
        $this->assertSame([$this->services[1]], $newEmployee->services()->pluck('branch_services.id')->all());
        $other = Branch::create(['name' => 'Other']);
        $this->actingAs($this->admin);
        Livewire::test(Operations::class)->set('selectedBranch', $other->id)->call('editEmployeeServices', $newEmployee->id)->assertNotFound();
        $this->assertSame([$this->services[1]], $newEmployee->services()->pluck('branch_services.id')->all());
    }
}
