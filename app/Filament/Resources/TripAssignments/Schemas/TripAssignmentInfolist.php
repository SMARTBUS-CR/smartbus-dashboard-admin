<?php

namespace App\Filament\Resources\TripAssignments\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TripAssignmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Recurring Assignment'))->schema([
                TextEntry::make('schedule.pattern.code')->label(__('Pattern Code')),
                TextEntry::make('schedule.pattern.name')->label(__('Pattern Name')),
                TextEntry::make('schedule.day_of_week')->label(__('Weekday')),
                TextEntry::make('schedule.departure_time')->label(__('Departure')),
                TextEntry::make('planned_arrival_time')->label(__('Planned Arrival')),
                IconEntry::make('ends_next_day')->label(__('Ends Next Day'))->boolean(),
                TextEntry::make('driver.user.name')->label(__('Driver')),
                TextEntry::make('bus.unit_number')->label(__('Bus')),
                TextEntry::make('valid_from')->label(__('Valid From'))->date(),
                TextEntry::make('valid_until')->label(__('Valid Until'))->date(),
                TextEntry::make('status')->label(__('Status'))->badge(),
            ])->columns(3)->columnSpanFull(),
        ]);
    }
}
