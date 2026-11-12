<?php

namespace App\Filament\Resources\Trips\Schemas;

use App\Enums\TripStatus;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TripInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Trip Identification'))
                ->schema([
                    TextEntry::make('id')->label(__('Trip Identifier')),
                    TextEntry::make('service_date')->label(__('Service Date')),
                    TextEntry::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => TripStatus::from($state)->getLabel())
                        ->color(fn (string $state): string => TripStatus::from($state)->getColor()),
                    TextEntry::make('route_schedule_id')->label(__('Route Schedule Identifier')),
                    TextEntry::make('bus.id')->label(__('Bus Identifier')),
                    TextEntry::make('driver.id')->label(__('Driver Employment Identifier')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('Planned Execution'))
                ->description(__('Local company time is shown with the original UTC instant in parentheses.'))
                ->schema([
                    TextEntry::make('scheduled_departure_display')->label(__('Planned Departure')),
                    TextEntry::make('scheduled_arrival_display')->label(__('Planned Arrival')),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Actual Execution'))
                ->schema([
                    TextEntry::make('actual_departure_display')->label(__('Actual Departure'))->placeholder(__('Not recorded')),
                    TextEntry::make('actual_arrival_display')->label(__('Actual Arrival'))->placeholder(__('Not recorded')),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Cancellation'))
                ->schema([
                    TextEntry::make('cancelled_display')->label(__('Cancellation Timestamp'))->placeholder(__('Not recorded')),
                    TextEntry::make('cancellation_reason')->label(__('Cancellation Reason'))->placeholder(__('Not recorded')),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Section::make(__('Immutable Route Snapshot'))
                ->description(__('This historical route information comes only from the saved Trip snapshot.'))
                ->schema([
                    TextEntry::make('planned_route_snapshot.pattern_code')->label(__('Pattern Code')),
                    TextEntry::make('planned_route_snapshot.pattern_name')->label(__('Pattern Name')),
                    TextEntry::make('planned_route_snapshot.origin')->label(__('Origin')),
                    TextEntry::make('planned_route_snapshot.destination')->label(__('Destination')),
                    TextEntry::make('planned_route_snapshot.company_timezone')->label(__('Company Timezone')),
                    TextEntry::make('route_geometry_display')
                        ->label(__('Stored Route Geometry'))
                        ->placeholder(__('Not recorded'))
                        ->columnSpanFull(),
                    RepeatableEntry::make('planned_route_snapshot.occurrences')
                        ->label(__('Ordered Planned Stops'))
                        ->table([
                            TableColumn::make(__('Sequence')),
                            TableColumn::make(__('Stop')),
                            TableColumn::make(__('Stop Identifier')),
                            TableColumn::make(__('Coordinates')),
                            TableColumn::make(__('Minutes From Start')),
                        ])
                        ->schema([
                            TextEntry::make('stop_sequence'),
                            TextEntry::make('name'),
                            TextEntry::make('stop_id'),
                            TextEntry::make('coordinates'),
                            TextEntry::make('minutes_from_start')->placeholder(__('Not recorded')),
                        ])
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ]);
    }
}
