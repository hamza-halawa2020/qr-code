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
    protected static ?string $navigationLabel = null;
    protected static ?string $title = null;

    public static function getNavigationLabel(): string { return __('queue.nav_reception'); }
    public function getTitle(): string { return __('queue.nav_reception'); }

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
                Notification::make()->title($ticket ? __('queue.rec_call_next') . ' ' . $ticket->display_number : __('queue.rec_no_waiting'))->success()->send();
            } elseif ($action !== 'pause') {
                Notification::make()->title($action === 'end_shift' ? __('queue.rec_end_shift') : __('queue.saved'))->success()->send();
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
            Notification::make()->title(__('queue.rec_manual_booking') . ': ' . $ticket->display_number)->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        return app(ReceptionDeskService::class)->viewData(auth()->user());
    }
}
