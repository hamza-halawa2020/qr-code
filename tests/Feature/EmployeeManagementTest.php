<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\User;
use App\Services\QueueService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeManagementTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $admin;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $this->admin = User::factory()->create(['role' => 'admin', 'branch_id' => $this->branch->id]);
        $this->agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_can_edit_and_disable_employee_and_panel_access_is_revoked(): void
    {
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Test', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $this->actingAs($this->admin);
        Livewire::test(Operations::class)->call('editEmployee', $this->agent->id)
            ->set('editEmployeeName', 'Updated agent')->set('editEmployeePassword', 'new-strong-password')
            ->set('editEmployeeActive', false)->call('saveEmployee')->assertHasNoErrors()->assertSet('editEmployeePassword', '');
        $agent = $this->agent->fresh();
        $this->assertSame('Updated agent', $agent->name);
        $this->assertTrue(Hash::check('new-strong-password', $agent->password));
        $this->assertFalse($agent->is_active);
        $this->assertTrue($counter->fresh()->is_paused);
        $this->actingAs($agent)->get('/admin/reception-desk')->assertForbidden();
    }

    public function test_manager_cannot_escalate_new_or_existing_employee_role(): void
    {
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->set('employeeName', 'Injected admin')->set('employeeEmail', 'injected@example.test')
            ->set('employeePassword', 'strong-test-password')->set('employeeRole', 'admin')->call('createEmployee')->assertHasErrors('employeeRole');
        $this->assertDatabaseMissing('users', ['email' => 'injected@example.test']);
        Livewire::test(Operations::class)->call('editEmployee', $this->agent->id)->set('editEmployeeRole', 'admin')->call('saveEmployee')->assertHasErrors('editEmployeeRole');
        $this->assertSame('agent', $this->agent->fresh()->role);
        Livewire::test(Operations::class)->call('editEmployee', $this->admin->id)->assertForbidden();
    }

    public function test_own_account_cannot_be_disabled_or_downgraded(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Operations::class)->call('editEmployee', $this->admin->id)->set('editEmployeeActive', false)->call('saveEmployee')->assertHasErrors('editEmployeeActive');
        $this->assertTrue($this->admin->fresh()->is_active);
        Livewire::test(Operations::class)->call('editEmployee', $this->admin->id)->set('editEmployeeRole', 'agent')->call('saveEmployee')->assertHasErrors('editEmployeeActive');
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_employee_with_active_ticket_cannot_be_disabled_until_service_finishes(): void
    {
        $counter = Counter::create(['branch_id' => $this->branch->id, 'name' => 'Test', 'number' => 1, 'user_id' => $this->agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $secret = Str::random(64);
        $queue = app(QueueService::class);
        $ticket = $queue->book($this->branch, $secret, $secret);
        $queue->next($counter, $this->agent);
        $this->actingAs($this->admin);
        Livewire::test(Operations::class)->call('editEmployee', $this->agent->id)->set('editEmployeeActive', false)->call('saveEmployee')->assertHasErrors('editEmployeeActive');
        $queue->transition($ticket, 'start', $this->agent);
        $queue->transition($ticket, 'complete', $this->agent);
        Livewire::test(Operations::class)->call('editEmployee', $this->agent->id)->set('editEmployeeActive', false)->call('saveEmployee')->assertHasNoErrors();
        $this->assertFalse($this->agent->fresh()->is_active);
        Livewire::test(Operations::class)->call('editEmployee', $this->agent->id)->set('editEmployeeActive', true)->call('saveEmployee')->assertHasNoErrors();
        $this->assertTrue($this->agent->fresh()->is_active);
    }

    public function test_admin_can_create_branch_manager_and_foreign_accounts_stay_inaccessible(): void
    {
        $this->actingAs($this->admin);
        Livewire::test(Operations::class)->set('employeeName', 'Branch manager')->set('employeeEmail', 'manager@example.test')
            ->set('employeePassword', 'strong-test-password')->set('employeeRole', 'manager')->call('createEmployee')->assertHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'manager@example.test', 'role' => 'manager', 'branch_id' => $this->branch->id]);
        $other = Branch::create(['name' => 'Other']);
        $foreign = User::factory()->create(['role' => 'agent', 'branch_id' => $other->id]);
        Livewire::test(Operations::class)->set('selectedBranch', $this->branch->id)->call('editEmployee', $foreign->id)->assertNotFound();
    }
}
