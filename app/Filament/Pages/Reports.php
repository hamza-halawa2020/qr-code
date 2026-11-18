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

    protected static ?string $navigationLabel = 'التقارير';

    protected static ?string $title = 'تقارير الانتظار والخدمة';

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
