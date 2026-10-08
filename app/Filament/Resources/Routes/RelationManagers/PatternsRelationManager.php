<?php

namespace App\Filament\Resources\Routes\RelationManagers;

use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternForm;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternInfolist;
use App\Filament\Resources\Routes\RouteResource;
use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class PatternsRelationManager extends RelationManager
{
    protected static string $relationship = 'patterns';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getTitle(
        Model $ownerRecord,
        string $pageClass,
    ): string {
        return __('Patterns');
    }

    public static function canViewForRecord(
        Model $ownerRecord,
        string $pageClass,
    ): bool {
        $company = Filament::getTenant();

        if (
            ! $ownerRecord instanceof Route
            || ! $company instanceof Company
            || (string) $ownerRecord->company_id !== (string) $company->getKey()
        ) {
            return false;
        }

        return RouteResource::canView($ownerRecord)
            && parent::canViewForRecord($ownerRecord, $pageClass);
    }

    public function mount(): void
    {
        abort_unless(
            static::canViewForRecord(
                $this->getOwnerRecord(),
                $this->getPageClass(),
            ),
            403,
        );

        parent::mount();
    }

    public function form(Schema $schema): Schema
    {
        return RoutePatternForm::configure(
            $schema,
            $this->getOwnerRecord(),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modelLabel(__('Pattern'))
            ->pluralModelLabel(__('Patterns'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label(__('Pattern Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('headsign')
                    ->label(__('Destination'))
                    ->searchable(),
            ])
            ->defaultSort('code')
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit Pattern'))
                    ->icon(Heroicon::PencilSquare)
                    ->color('primary')
                    ->authorize(
                        fn (RoutePattern $record): bool =>
                            RoutePatternResource::canEdit($record),
                    )
                    ->url(fn (RoutePattern $record): string =>
                        RoutePatternResource::getUrl(
                            'edit',
                            [
                                'route' => $this->getOwnerRecord()->getKey(),
                                'record' => $record->getKey(),
                            ],
                            panel: 'admin',
                            tenant: Filament::getTenant(),
                        ),
                    ),

                Action::make('view')
                    ->label(__('View Pattern'))
                    ->icon(Heroicon::Eye)
                    ->color('info')
                    ->authorize(
                        fn (RoutePattern $record): bool =>
                            ! RoutePatternResource::canEdit($record)
                            && RoutePatternResource::canView($record),
                    )
                    ->url(fn (RoutePattern $record): string =>
                        RoutePatternResource::getUrl(
                            'view',
                            [
                                'route' => $this->getOwnerRecord()->getKey(),
                                'record' => $record->getKey(),
                            ],
                            panel: 'admin',
                            tenant: Filament::getTenant(),
                        ),
                    ),
            ]);
    }

    public function infolist(Schema $schema): Schema
    {
        return RoutePatternInfolist::configure($schema);
    }
}
