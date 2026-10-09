<?php

namespace App\Filament\Resources\Routes\RelationManagers;

use App\Enums\LucideIcon;
use App\Filament\Resources\Routes\Resources\RoutePatterns\RoutePatternResource;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternForm;
use App\Filament\Resources\Routes\Resources\RoutePatterns\Schemas\RoutePatternInfolist;
use App\Filament\Resources\Routes\RouteResource;
use App\Filament\Support\TableSectionHeader;
use App\Models\Company;
use App\Models\Route;
use App\Models\RoutePattern;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PatternsRelationManager extends RelationManager
{
    protected static string $relationship = 'patterns';

    protected static string|\BackedEnum|null $icon = LucideIcon::Route;

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
            ->heading(TableSectionHeader::heading(
                __('Patterns'),
                LucideIcon::Route,
            ))
            ->description(TableSectionHeader::description(__(
                'Configure the travel patterns of this route. Each pattern has its own ordered stops and departure schedules.',
            )))
            ->recordTitleAttribute('name')
            ->modelLabel(__('Pattern'))
            ->pluralModelLabel(__('Patterns'))
            ->columns([
                TextColumn::make('code')
                    ->label(__('Code'))
                    ->fontFamily(FontFamily::Mono)
                    ->color(Color::Gray)
                    ->badge()
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
                CreateAction::make()
                    ->modalHeading(__('Create Pattern'))
                    ->modalDescription(__(
                        'Define the code, name and destination. You can configure stops and departures after creating the pattern.',
                    ))
                    ->modalIcon(LucideIcon::Route)
                    ->modalIconColor('primary')
                    ->modalAlignment(Alignment::Start)
                    ->modalWidth(Width::Large),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('Edit Pattern'))
                    ->icon(Heroicon::PencilSquare)
                    ->color('primary')
                    ->authorize(
                        fn (RoutePattern $record): bool => RoutePatternResource::canEdit($record),
                    )
                    ->url(fn (RoutePattern $record): string => RoutePatternResource::getUrl(
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
                        fn (RoutePattern $record): bool => ! RoutePatternResource::canEdit($record)
                            && RoutePatternResource::canView($record),
                    )
                    ->url(fn (RoutePattern $record): string => RoutePatternResource::getUrl(
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
