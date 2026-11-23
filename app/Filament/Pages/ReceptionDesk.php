<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ReceptionDeskService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Dehydrated;

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

    public ?int $requeueServiceId = null;

    // Profile edit
    public string $profileName = '';
    public string $profilePhone = '';
    #[Dehydrated(false)]
    public string $profilePassword = '';

    public function mount(): void
    {
        $this->operationKey = (string) Str::uuid();
        $user = auth()->user();
        $this->profileName  = $user->name;
        $this->profilePhone = $user->phone ?? '';
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

    public function requeueTicket(int $ticketId): void
    {
        try {
            app(ReceptionDeskService::class)->requeueTicket(auth()->user(), $this->operationKey, $ticketId, $this->requeueServiceId ?: null);
            $this->operationKey = (string) Str::uuid();
            $this->requeueServiceId = null;
            Notification::make()->title(__('queue.rec_requeued'))->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title(collect($exception->errors())->flatten()->first())->warning()->send();
        }
    }

    public function saveProfile(): void
    {
        $user = auth()->user();
        $data = $this->validate([
            'profileName'     => 'required|string|max:120',
            'profilePhone'    => ['required', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($user->id)],
            'profilePassword' => 'nullable|string|min:12|max:128',
        ]);

        $values = [
            'name'  => $data['profileName'],
            'phone' => $data['profilePhone'],
        ];
        if (filled($data['profilePassword'])) {
            $values['password'] = Hash::make($data['profilePassword']);
        }

        User::whereKey($user->id)->update($values);

        $this->profilePassword = '';
        $this->resetValidation();
        Notification::make()->title(__('queue.saved'))->success()->send();
    }

    protected function getViewData(): array
    {
        return app(ReceptionDeskService::class)->viewData(auth()->user());
    }
}
