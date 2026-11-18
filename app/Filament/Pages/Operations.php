<?php

namespace App\Filament\Pages;

use App\Services\BranchAccessService;
use App\Services\BranchBoardService;
use App\Services\EmployeeServiceService;
use App\Services\OperationsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class Operations extends Page
{
    protected static ?string $navigationLabel = null;
    protected static ?string $title = null;

    public static function getNavigationLabel(): string { return __('queue.nav_operations'); }
    public function getTitle(): string { return __('queue.nav_operations'); }

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.operations';

    public string $serviceName = '';

    #[Locked]
    public ?int $serviceEmployeeId = null;

    public array $employeeServiceIds = [];

    public string $branchName = '';

    public string $opensAt = '09:00';

    public string $closesAt = '17:00';

    public ?int $selectedBranch = null;

    public string $employeeName = '';

    public string $employeeEmail = '';

    public string $employeePassword = '';

    public string $employeeRole = 'agent';

    #[Locked]
    public ?int $editingEmployeeId = null;

    public string $editEmployeeName = '';

    public string $editEmployeeEmail = '';

    public string $editEmployeeRole = 'agent';

    public bool $editEmployeeActive = true;

    public string $editEmployeePassword = '';

    public string $counterName = '';

    public int $counterNumber = 1;

    #[Locked]
    public ?int $editingCounterId = null;

    public string $editCounterName = '';

    public int $editCounterNumber = 1;

    public ?int $employeeId = null;

    public ?int $assignmentEmployeeId = null;

    public int $noShowSeconds = 60;

    public bool|int $automaticQueue = 0;

    public int $missedTurnRetries = 0;

    public string $displayLocale = 'ar';

    public string $panelLocale = 'ar';

    #[Locked]
    public ?string $displayUrl = null;

    #[Locked]
    public ?string $boardUrl = null;

    public static function canAccess(): bool
    {
        return app(BranchAccessService::class)->canManage(auth()->user());
    }

    public function mount(): void
    {
        $this->selectedBranch = $this->branches()->first()?->id;
        $this->updatedSelectedBranch();
    }

    public function updatedSelectedBranch(): void
    {
        $branch = $this->branches()->firstWhere('id', $this->selectedBranch);
        if ($branch) {
            $this->opensAt = $branch->opens_at;
            $this->closesAt = $branch->closes_at;
            $this->noShowSeconds = $branch->no_show_after_seconds;
            $this->automaticQueue = (int) $branch->automatic_queue;
            $this->missedTurnRetries = $branch->missed_turn_retries;
            $this->displayLocale = $branch->display_locale ?? 'ar';
            $this->panelLocale = $branch->panel_locale ?? 'ar';
        }
        $this->displayUrl = null;
        $this->boardUrl = null;
        $this->employeeId = null;
        $this->assignmentEmployeeId = null;
        $this->closeEmployee();
        $this->closeCounter();
        $this->closeEmployeeServices();
    }

    public function createBranch(): void
    {
        $branch = $this->service()->createBranch(auth()->user(), $this->only(['branchName', 'opensAt', 'closesAt']));
        $this->selectedBranch = $branch->id;
        $this->branchName = '';
        $this->done();
    }

    public function saveHours(): void
    {
        $this->service()->saveSettings(auth()->user(), $this->selectedBranch, $this->only(['opensAt', 'closesAt']));
        $this->done();
    }

    public function saveAutomation(): void
    {
        $this->service()->saveAutomation(auth()->user(), $this->selectedBranch, $this->only(['automaticQueue', 'noShowSeconds', 'missedTurnRetries', 'displayLocale', 'panelLocale']));
        $this->resetValidation(['automaticQueue', 'noShowSeconds', 'missedTurnRetries', 'displayLocale', 'panelLocale']);
        $this->done();
    }

    public function toggleBooking(): void
    {
        $this->service()->toggleBooking(auth()->user(), $this->selectedBranch);
        $this->done();
    }

    public function createService(): void
    {
        $this->service()->createService(auth()->user(), $this->selectedBranch, $this->serviceName);
        $this->serviceName = '';
        $this->done();
    }

    public function toggleService(int $serviceId): void
    {
        $this->service()->toggleService(auth()->user(), $this->selectedBranch, $serviceId);
        $this->done();
    }

    public function createEmployee(): void
    {
        $this->service()->createEmployee(auth()->user(), $this->selectedBranch, $this->only(['employeeName', 'employeeEmail', 'employeePassword', 'employeeRole']));
        $this->reset('employeeName', 'employeeEmail', 'employeePassword');
        $this->done();
    }

    public function editEmployeeServices(int $employeeId): void
    {
        $employee = app(EmployeeServiceService::class)->employee(auth()->user(), $this->selectedBranch, $employeeId);
        $this->serviceEmployeeId = $employee->id;
        $this->employeeServiceIds = $employee->services()->where('branch_id', $this->selectedBranch)->pluck('branch_services.id')->all();
        $this->resetValidation();
    }

    public function saveEmployeeServices(): void
    {
        abort_unless($this->serviceEmployeeId, 422);
        try {
            app(EmployeeServiceService::class)->save(auth()->user(), $this->selectedBranch, $this->serviceEmployeeId, $this->employeeServiceIds);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['employeeServiceIds' => collect($exception->errors())->flatten()->first()]);
        }
        $this->closeEmployeeServices();
        $this->done();
    }

    public function closeEmployeeServices(): void
    {
        $this->serviceEmployeeId = null;
        $this->employeeServiceIds = [];
        $this->resetValidation('employeeServiceIds');
    }

    public function createCounter(): void
    {
        $this->displayUrl = $this->service()->createCounter(auth()->user(), $this->selectedBranch, $this->only(['counterName', 'counterNumber', 'employeeId']));
        $this->reset('counterName', 'employeeId');
        $this->done();
    }

    public function editEmployee(int $employeeId): void
    {
        $this->fill($this->service()->employeeData(auth()->user(), $this->selectedBranch, $employeeId));
        $this->editingEmployeeId = $employeeId;
    }

    public function closeEmployee(): void
    {
        $this->editingEmployeeId = null;
        $this->reset('editEmployeeName', 'editEmployeeEmail', 'editEmployeeRole', 'editEmployeeActive', 'editEmployeePassword');
    }

    public function saveEmployee(): void
    {
        abort_unless($this->editingEmployeeId, 422);
        $this->service()->updateEmployee(auth()->user(), $this->selectedBranch, $this->editingEmployeeId, $this->only(['editEmployeeName', 'editEmployeeEmail', 'editEmployeeRole', 'editEmployeeActive', 'editEmployeePassword']));
        $this->closeEmployee();
        $this->done();
    }

    public function rotateDisplay(int $counterId): void
    {
        $this->displayUrl = $this->service()->rotateDisplay(auth()->user(), $this->selectedBranch, $counterId);
        $this->done();
    }

    public function editCounter(int $counterId): void
    {
        $this->fill($this->service()->counterData(auth()->user(), $this->selectedBranch, $counterId));
        $this->editingCounterId = $counterId;
    }

    public function rotateBoard(): void
    {
        $this->boardUrl = app(BranchBoardService::class)->rotate(auth()->user(), $this->selectedBranch);
        $this->done();
    }

    public function closeCounter(): void
    {
        $this->editingCounterId = null;
        $this->reset('editCounterName', 'editCounterNumber');
    }

    public function saveCounter(): void
    {
        abort_unless($this->editingCounterId, 422);
        $this->service()->updateCounter(auth()->user(), $this->selectedBranch, $this->editingCounterId, $this->only(['editCounterName', 'editCounterNumber']));
        $this->closeCounter();
        $this->done();
    }

    public function assignEmployee(int $counterId): void
    {
        $this->service()->assignEmployee(auth()->user(), $this->selectedBranch, $counterId, $this->assignmentEmployeeId);
        $this->assignmentEmployeeId = null;
        $this->done();
    }

    private function branches()
    {
        return app(BranchAccessService::class)->branches(auth()->user());
    }

    private function service(): OperationsService
    {
        return app(OperationsService::class);
    }

    private function done(): void
    {
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        return $this->service()->viewData(auth()->user(), $this->selectedBranch);
    }
}
