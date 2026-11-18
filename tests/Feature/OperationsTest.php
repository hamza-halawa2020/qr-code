<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\User;
use App\Services\QueueService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_branch_selection_loads_actual_hours_and_policy(): void
    {
        $branch = Branch::create(['name' => 'First', 'opens_at' => '08:00', 'closes_at' => '16:00', 'no_show_after_seconds' => 90]);
        $other = Branch::create(['name' => 'Second', 'opens_at' => '11:00', 'closes_at' => '20:00', 'no_show_after_seconds' => 120]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Operations::class)->assertSet('opensAt', '08:00')->assertSet('noShowSeconds', 90)
            ->set('selectedBranch', $other->id)->assertSet('opensAt', '11:00')->assertSet('closesAt', '20:00')->assertSet('noShowSeconds', 120);
    }

    public function test_manager_cannot_modify_another_branch(): void
    {
        $own = Branch::create(['name' => 'Own']);
        $other = Branch::create(['name' => 'Other']);
        $this->actingAs(User::factory()->create(['role' => 'manager', 'branch_id' => $own->id]));
        Livewire::test(Operations::class)->set('selectedBranch', $other->id)->call('toggleBooking')->assertForbidden();
        $this->assertTrue($other->fresh()->is_open);
    }

    public function test_counter_can_be_assigned_to_an_unassigned_employee(): void
    {
        $branch = Branch::create(['name' => 'Test']);
        $employee = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'First', 'number' => 1, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Operations::class)->set('assignmentEmployeeId', $employee->id)->call('assignEmployee', $counter->id)->assertHasNoErrors();
        $this->assertSame($employee->id, $counter->fresh()->user_id);
    }

    public function test_counter_assignment_cannot_change_during_service(): void
    {
        $this->travelTo(now()->setTime(10, 0));
        $branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $first = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        $second = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'First', 'number' => 1, 'user_id' => $first->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $secret = Str::random(64);
        app(QueueService::class)->book($branch, $secret, $secret);
        app(QueueService::class)->next($counter, $first);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Operations::class)->set('assignmentEmployeeId', $second->id)->call('assignEmployee', $counter->id)->assertHasErrors('assignmentEmployeeId');
        $this->assertSame($first->id, $counter->fresh()->user_id);
    }

    public function test_counter_details_can_be_updated_without_changing_assignment(): void
    {
        $branch = Branch::create(['name' => 'Test']);
        $employee = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'Original', 'number' => 1, 'user_id' => $employee->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Operations::class)->call('editCounter', $counter->id)->set('editCounterName', 'Updated window')->set('editCounterNumber', 3)->call('saveCounter')->assertHasNoErrors();
        $this->assertSame('Updated window', $counter->fresh()->name);
        $this->assertSame(3, $counter->fresh()->number);
        $this->assertSame($employee->id, $counter->fresh()->user_id);
    }
}
