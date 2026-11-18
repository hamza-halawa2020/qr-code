<?php

namespace App\Filament\Pages;

use App\Services\ReceptionDeskService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class ReceptionDesk extends Page
{
    protected static ?string $navigationLabel = 'شاشة الموظف';

    protected static ?string $title = 'شاشة الموظف';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reception-desk';

    #[Locked]
    public string $operationKey;

    public ?int $manualServiceId = null;

    public string $manualPhone = '';

    public function mount(): void
    {
        $this->operationKey = (string) Str::uuid();
    }

    public function act(string $action, ?int $expectedTicketId = null): void
    {
        try {
            $ticket = app(ReceptionDeskService::class)->command(auth()->user(), $this->operationKey, $action, $expectedTicketId);
            $this->operationKey = (string) Str::uuid();
            if ($action === 'next') {
                Notification::make()->title($ticket ? 'تم استدعاء رقم '.$ticket->display_number : 'لا يوجد عملاء منتظرون مناسبون لخدماتك')->success()->send();
            } elseif ($action !== 'pause') {
                Notification::make()->title($action === 'end_shift' ? 'تم إنهاء الوردية' : 'تم تحديث التذكرة')->success()->send();
            }
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
        }
    }

    public function manualBooking(): void
    {
        try {
            $ticket = app(ReceptionDeskService::class)->manualCommand(auth()->user(), $this->operationKey, ['service_id' => $this->manualServiceId, 'customer_phone' => $this->manualPhone ?: null]);
            $this->operationKey = (string) Str::uuid();
            $this->reset('manualServiceId', 'manualPhone');
            Notification::make()->title('رقم العميل: '.$ticket->display_number)->body('يُتابع النداء على شاشة الفرع.')->persistent()->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        return app(ReceptionDeskService::class)->viewData(auth()->user());
    }
}
