<?php

namespace App\Filament\Resources\Services;

use App\Filament\Resources\Services\Pages\ManageServices;
use App\Models\Service;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    // protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string { return __('queue.nav_services'); }

    public static function getModelLabel(): string { return __('queue.svc_model'); }

    public static function getPluralModelLabel(): string { return __('queue.nav_services'); }

    public static function canAccess(): bool
    {
        return auth()->user()?->is_active && auth()->user()->role === 'admin';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('queue.svc_name'))
                ->required()
                ->maxLength(120)
                ->unique(ignoreRecord: true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('queue.svc_name'))->searchable()->sortable(),
                TextColumn::make('branch_services_count')->label(__('queue.svc_branches_count'))->counts('branchServices')->badge(),
                TextColumn::make('created_at')->label(__('queue.svc_created'))->dateTime()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->visible(fn (Service $record) => $record->branchServices()->doesntExist()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
