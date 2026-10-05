<?php

namespace App\Filament\Resources\SuperAdmins\Tables;

use App\Filament\Resources\SuperAdmins\SuperAdminResource;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class SuperAdminsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('deactivateSuperAdmin')
                    ->label(__('Deactivate'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->authorize(
                        fn (User $record): bool => SuperAdminResource::canEdit($record)
                    )
                    ->requiresConfirmation()
                    ->modalHeading(__('Deactivate System Admin'))
                    ->modalSubmitActionLabel(__('Deactivate'))
                    ->modalDescription(__(
                        'This account will lose access to the system and its tokens will be revoked. Its data will be preserved.'
                    ))
                    ->schema([
                        Text::make(function (Text $component, Component $livewire): string {
                            $statePath = $component->getContainer()->getStatePath();

                            $errorKey = filled($statePath)
                                ? "{$statePath}.access"
                                : 'access';

                            return $livewire->getErrorBag()->first($errorKey);
                        })
                            ->color('danger'),
                    ])
                    ->databaseTransaction(false)
                    ->action(function (User $record, Component $livewire): void {
                        try {
                            app(UserManagementService::class)->deactivateSuperAdmin(
                                Filament::auth()->user(),
                                $record,
                            );
                        } catch (ValidationException $exception) {
                            $schemaName = $livewire->getMountedActionSchemaName();

                            $schema = $schemaName !== null
                                ? $livewire->getSchema($schemaName)
                                : null;

                            $statePath = $schema?->getStatePath();
                            $errors = [];

                            foreach ($exception->errors() as $field => $messages) {
                                $key = filled($statePath)
                                    ? "{$statePath}.{$field}"
                                    : $field;

                                $errors[$key] = $messages;
                            }

                            throw ValidationException::withMessages($errors);
                        }

                        Notification::make()
                            ->title(__('System Admin Deactivated'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
