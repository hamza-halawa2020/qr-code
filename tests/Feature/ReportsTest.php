<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Filament\Pages\Reports;
use App\Models\Branch;
use App\Models\Ticket;
use App\Models\User;
use App\Services\QueueReportService;
use App\Services\QueueService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        config(['firebase.enabled' => false]);
        $this->withoutVite();
        $this->travelTo(now()->setTime(10, 0));
        $this->branch = Branch::create(['name' => 'Own branch', 'timezone' => 'UTC']);
        $this->manager = User::factory()->create(['role' => 'manager', 'branch_id' => $this->branch->id]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function book(?Branch $branch = null): Ticket
    {
        $secret = Str::random(64);

        return app(QueueService::class)->book($branch ?? $this->branch, $secret, $secret);
    }

    public function test_status_filter_changes_ticket_rows_without_changing_daily_totals(): void
    {
        $waiting = $this->book();
        app(QueueService::class)->transition($this->book(), 'cancel');
        $report = app(QueueReportService::class)->daily($this->manager, now()->toDateString(), null, 'waiting');
        $this->assertCount(2, $report['tickets']);
        $this->assertSame(1, $report['ticketRows']->total());
        $this->assertSame($waiting->id, $report['ticketRows']->first()->id);
        $this->assertSame(1, $report['counts']['cancelled']);
    }

    public function test_pagination_does_not_omit_tickets_older_than_first_page(): void
    {
        $first = $this->book();
        for ($i = 0; $i < 25; $i++) {
            $this->book();
        }
        $report = app(QueueReportService::class)->daily($this->manager, now()->toDateString(), null, null, 2);
        $this->assertSame(26, $report['ticketRows']->total());
        $this->assertCount(1, $report['ticketRows']);
        $this->assertSame($first->id, $report['ticketRows']->first()->id);
    }

    public function test_employee_statistics_and_peak_hour_use_completed_tickets_and_branch_time(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'branch_id' => $this->branch->id]);
        $ticket = $this->book();
        $ticket->update(['user_id' => $agent->id, 'status' => TicketStatus::Completed, 'started_at' => now()->addMinutes(2), 'finished_at' => now()->addMinutes(6)]);
        $this->book()->update(['user_id' => $agent->id, 'status' => TicketStatus::NoShow]);
        $this->travel(1)->hours();
        $this->book();
        $report = app(QueueReportService::class)->daily($this->manager, now()->toDateString(), null);
        $this->assertSame(1, $report['employeeStats']->first()['completed']);
        $this->assertSame($agent->name, $report['employeeStats']->first()['name']);
        $this->assertEquals(4, $report['employeeStats']->first()['serviceMinutes']);
        $this->assertSame('10:00', $report['peakHour']);
        $this->assertSame(2, $report['hourlyStats']['10:00']);
    }

    public function test_history_shows_recorded_transitions_and_hides_other_branch_tickets(): void
    {
        $ticket = $this->book();
        app(QueueService::class)->transition($ticket, 'cancel');
        $this->actingAs($this->manager);
        Livewire::test(Reports::class)->call('viewTicket', $ticket->id)->assertSee('حجز التذكرة')->assertSee('إلغاء التذكرة');
        $other = Branch::create(['name' => 'Other branch', 'timezone' => 'UTC']);
        $foreign = $this->book($other);
        Livewire::test(Reports::class)->call('viewTicket', $foreign->id)->assertNotFound();
    }

    public function test_filter_change_resets_pagination_and_closes_history(): void
    {
        $ticket = $this->book();
        $this->actingAs($this->manager);
        Livewire::test(Reports::class)->call('viewTicket', $ticket->id)->call('setPage', 2)
            ->set('status', 'waiting')->assertSet('paginators.page', 1)->assertSet('selectedTicketId', null);
    }
}
