<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Services\OperationsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BranchScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function weeklyHours(): array
    {
        return [
            0 => ['is_closed' => true, 'opens_at' => null, 'closes_at' => null],
            1 => ['is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '14:00'],
            2 => ['is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            3 => ['is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            4 => ['is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '17:00'],
            5 => ['is_closed' => false, 'opens_at' => '09:00', 'closes_at' => '15:00'],
            6 => ['is_closed' => false, 'opens_at' => '10:00', 'closes_at' => '16:00'],
        ];
    }

    public function test_weekly_hours_are_persisted_and_control_booking(): void
    {
        $branch = Branch::create(['name' => 'Schedule', 'timezone' => 'UTC', 'opens_at' => '08:00', 'closes_at' => '18:00']);
        $admin = User::factory()->create(['role' => 'admin']);

        app(OperationsService::class)->saveWeeklyHours($admin, $branch->id, ['weeklyHours' => $this->weeklyHours()]);

        $this->assertDatabaseHas('branch_schedules', ['branch_id' => $branch->id, 'day_of_week' => 0, 'is_closed' => 1]);
        $this->assertDatabaseHas('branch_schedules', ['branch_id' => $branch->id, 'day_of_week' => 1, 'opens_at' => '10:00', 'closes_at' => '14:00']);
        $this->assertSame('10:00', $branch->fresh()->opens_at);

        // 2026-11-22 is Sunday (closed), 2026-11-23 is Monday (10:00–14:00).
        $this->assertFalse($branch->fresh()->acceptsBookingsAt(Carbon::parse('2026-11-22 11:00', 'UTC')));
        $this->assertTrue($branch->fresh()->acceptsBookingsAt(Carbon::parse('2026-11-23 11:00', 'UTC')));
        $this->assertFalse($branch->fresh()->acceptsBookingsAt(Carbon::parse('2026-11-23 09:30', 'UTC')));
    }

    public function test_customer_pages_show_the_weekly_schedule(): void
    {
        $branch = Branch::create(['name' => 'Schedule', 'timezone' => 'UTC']);
        $admin = User::factory()->create(['role' => 'admin']);
        app(OperationsService::class)->saveWeeklyHours($admin, $branch->id, ['weeklyHours' => $this->weeklyHours()]);

        $this->get('/')->assertOk()->assertSee('09:00 — 15:00');
        $this->get(route('branch.show', $branch))
            ->assertOk()
            ->assertSee(__('queue.day_sun'))
            ->assertSee(__('queue.day_closed'))
            ->assertSee('10:00 — 14:00');
    }
}
