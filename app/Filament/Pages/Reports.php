<?php

namespace App\Filament\Pages;

use App\Services\BranchAccessService;
use App\Services\QueueReportService;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\WithPagination;

class Reports extends Page
{
    use WithPagination;

    protected static ?string $navigationLabel = null;
    protected static ?string $title = null;

    public static function getNavigationLabel(): string { return __('queue.nav_reports'); }
    public function getTitle(): string { return __('queue.nav_reports'); }

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.reports';

    public string $date = '';

    public ?int $branchId = null;

    public string $status = '';

    #[Locked]
    public ?int $selectedTicketId = null;

    public static function canAccess(): bool
    {
        return app(BranchAccessService::class)->canManage(auth()->user());
    }

    public function mount(): void
    {
        $this->date = now('Africa/Cairo')->toDateString();
    }

    public function updatedDate(): void
    {
        $this->resetFiltersPage();
    }

    public function updatedBranchId(): void
    {
        $this->resetFiltersPage();
    }

    public function updatedStatus(): void
    {
        $this->resetFiltersPage();
    }

    public function viewTicket(int $ticketId): void
    {
        app(QueueReportService::class)->history(auth()->user(), $ticketId);
        $this->selectedTicketId = $ticketId;
    }

    public function closeHistory(): void
    {
        $this->selectedTicketId = null;
    }

    private function resetFiltersPage(): void
    {
        $this->resetPage();
        $this->selectedTicketId = null;
    }

    protected function getViewData(): array
    {
        $service = app(QueueReportService::class);
        $data = $service->daily(auth()->user(), $this->date, $this->branchId, $this->status, $this->getPage());
        $data['history'] = $this->selectedTicketId ? $service->history(auth()->user(), $this->selectedTicketId) : null;

        return $data;
    }
}
