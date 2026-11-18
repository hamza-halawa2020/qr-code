<?php

namespace App\Filament\Pages;

use App\Services\QueueHealthService;
use Filament\Pages\Page;

class SystemStatus extends Page
{
    protected static ?string $navigationLabel = null;
    protected static ?string $title = null;

    public static function getNavigationLabel(): string { return __('queue.nav_system'); }
    public function getTitle(): string { return __('queue.nav_system'); }

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.system-status';

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()->role === 'admin';
    }

    protected function getViewData(): array
    {
        return app(QueueHealthService::class)->report(auth()->user());
    }
}
