<?php

namespace Tests\Feature;

use App\Filament\Pages\Operations;
use App\Filament\Pages\ReceptionDesk;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use App\Services\QueueReportService;
use App\Services\QueueService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class StaffServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['firebase.enabled' => false]);
        $this->travelTo(now()->setTime(10, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function ticket(Branch $branch): Ticket
    {
        $secret = Str::random(64);

        return app(QueueService::class)->book($branch, $secret, $secret);
    }

    public function test_report_calculates_wait_and_service_and_excludes_other_branches(): void
    {
        $branch = Branch::create(['name' => 'Own', 'timezone' => 'UTC']);
        $other = Branch::create(['name' => 'Other', 'timezone' => 'UTC']);
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $branch->id]);
        $ticket = $this->ticket($branch);
        $ticket->update(['status' => 'completed', 'started_at' => now()->addMinutes(10), 'finished_at' => now()->addMinutes(15)]);
        $this->ticket($branch)->update(['status' => 'cancelled']);
        $this->ticket($other);
        $report = app(QueueReportService::class)->daily($manager, now()->toDateString(), null);
        $this->assertCount(2, $report['tickets']);
        $this->assertEquals(10, $report['waitMinutes']);
        $this->assertEquals(5, $report['serviceMinutes']);
        $this->assertSame(1, $report['counts']['completed']);
        $this->assertCount(0, app(QueueReportService::class)->daily($manager, now()->toDateString(), $other->id)['tickets']);
    }

    public function test_reception_page_still_calls_starts_and_completes_customer(): void
    {
        $branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        Counter::create(['branch_id' => $branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $ticket = $this->ticket($branch);
        $this->actingAs($agent);
        $page = Livewire::test(ReceptionDesk::class)->call('act', 'next')->assertSee('001');
        $this->assertSame('called', $ticket->fresh()->status->value);
        $page->call('act', 'start')->call('act', 'complete');
        $this->assertSame('completed', $ticket->fresh()->status->value);
    }

    public function test_reception_without_counter_cannot_book_or_call(): void
    {
        $branch = Branch::create(['name' => 'Test']);
        $this->actingAs(User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]));
        Livewire::test(ReceptionDesk::class)->call('manualBooking')->assertForbidden();
        Livewire::test(ReceptionDesk::class)->call('act', 'next')->assertForbidden();
        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_employee_creation_hashes_password_and_preserves_branch(): void
    {
        $branch = Branch::create(['name' => 'Test']);
        $manager = User::factory()->create(['role' => 'manager', 'branch_id' => $branch->id]);
        $this->actingAs($manager);
        Livewire::test(Operations::class)->set('employeeName', 'New agent')->set('employeeEmail', 'new@example.test')
            ->set('employeePassword', 'strong-test-password')->call('createEmployee')->assertHasNoErrors()->assertSet('employeePassword', '');
        $employee = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($branch->id, $employee->branch_id);
        $this->assertSame('agent', $employee->role);
        $this->assertTrue(Hash::check('strong-test-password', $employee->password));
    }

    public function test_branch_validation_errors_still_reach_livewire_form(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Livewire::test(Operations::class)->set('branchName', 'Invalid hours')->set('opensAt', '17:00')
            ->set('closesAt', '09:00')->call('createBranch')->assertHasErrors('closesAt');
        $this->assertDatabaseCount('branches', 0);
    }

    public function test_pause_blocks_calls_and_resume_and_manual_booking_work(): void
    {
        $branch = Branch::create(['name' => 'Test', 'timezone' => 'UTC']);
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $branch->id]);
        $counter = Counter::create(['branch_id' => $branch->id, 'name' => 'Window', 'number' => 1, 'user_id' => $agent->id, 'display_token_hash' => hash('sha256', Str::random(64))]);
        $this->actingAs($agent);
        $service = $branch->services()->create(['name' => 'استعلام']);
        $agent->services()->sync([$service->id]);
        $page = Livewire::test(ReceptionDesk::class)->set('manualServiceId', $service->id)->call('manualBooking')->call('act', 'pause');
        $this->assertTrue($counter->fresh()->is_paused);
        $page->call('act', 'next');
        $this->assertSame('waiting', Ticket::firstOrFail()->status->value);
        $page->call('act', 'pause')->call('act', 'next');
        $this->assertFalse($counter->fresh()->is_paused);
        $this->assertSame('called', Ticket::firstOrFail()->status->value);
    }
}
