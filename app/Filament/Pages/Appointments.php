<?php

namespace App\Filament\Pages;

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

    public ?int $serviceId = null;

    public string $date = '';

    public string $time = '10:00';

    public int $capacity = 1;

    public static function canAccess(): bool
    {
        return app(BranchAccessService::class)->canManage(auth()->user());
    }

    public function mount(): void
    {
        $this->branchId = app(BranchAccessService::class)->branches(auth()->user())->first()?->id;
        $this->date = now('Africa/Cairo')->addDay()->toDateString();
    }

    public function updatedBranchId(): void
    {
        $this->serviceId = null;
    }

    public function createSlot(): void
    {
        app(AppointmentService::class)->createSlot(auth()->user(), $this->branchId, $this->only(['serviceId', 'date', 'time', 'capacity']));
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        return app(AppointmentService::class)->adminData(auth()->user(), $this->branchId);
    }
}
