<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\AppointmentService;
use App\Services\BranchAccessService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Appointments extends Page
{
    protected static ?string $navigationLabel = null;
    protected static ?string $title = null;

    public static function getNavigationLabel(): string { return __('queue.nav_appointments'); }
    public function getTitle(): string { return __('queue.nav_appointments'); }

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.appointments';

    public ?int $branchId = null;

    public int $appointmentInterval = 60;

    public static function canAccess(): bool
    {
        return app(BranchAccessService::class)->canManage(auth()->user());
    }

    public function mount(): void
    {
        $branch = app(BranchAccessService::class)->branches(auth()->user())->first();
        $this->branchId = $branch?->id;
        $this->appointmentInterval = (int) ($branch?->appointment_interval_minutes ?: 60);
    }

    public function updatedBranchId(): void
    {
        $this->appointmentInterval = (int) (Branch::find($this->branchId)?->appointment_interval_minutes ?: 60);
    }

    public function saveInterval(): void
    {
        app(AppointmentService::class)->saveInterval(auth()->user(), $this->branchId, $this->appointmentInterval);
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        return app(AppointmentService::class)->adminData(auth()->user(), $this->branchId);
    }
}
