<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\AppointmentService;
use App\Services\BranchAccessService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

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

    public bool $appointmentsEnabled = true;

    public int $bookingLeadHours = 0;

    public static function canAccess(): bool
    {
        return app(BranchAccessService::class)->canManage(auth()->user());
    }

    public function mount(): void
    {
        $branch = app(BranchAccessService::class)->branches(auth()->user())->first();
        $this->branchId = $branch?->id;
        $this->appointmentInterval = (int) ($branch?->appointment_interval_minutes ?: 60);
        $this->appointmentsEnabled = (bool) ($branch?->appointments_enabled ?? true);
        $this->bookingLeadHours = (int) ($branch?->booking_lead_hours ?? 0);
    }

    public function updatedBranchId(): void
    {
        $branch = Branch::find($this->branchId);
        $this->appointmentInterval = (int) ($branch?->appointment_interval_minutes ?: 60);
        $this->appointmentsEnabled = (bool) ($branch?->appointments_enabled ?? true);
        $this->bookingLeadHours = (int) ($branch?->booking_lead_hours ?? 0);
    }

    public function saveInterval(): void
    {
        try {
            app(AppointmentService::class)->saveInterval(auth()->user(), $this->branchId, $this->appointmentInterval);
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

            throw $exception;
        }
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    public function saveConfig(): void
    {
        try {
            app(AppointmentService::class)->saveConfig(auth()->user(), $this->branchId, [
                'appointmentsEnabled' => $this->appointmentsEnabled,
                'bookingLeadHours'    => $this->bookingLeadHours,
            ]);
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->danger()->send();

            throw $exception;
        }
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        return app(AppointmentService::class)->adminData(auth()->user(), $this->branchId);
    }
}
