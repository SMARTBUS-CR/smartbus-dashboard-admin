<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use App\Services\UserManagementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Schemas\Components\Text;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('Name'))
                    ->searchable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('removeCompanyAccess')
                    ->label(__('Retirar acceso'))
                    ->color('danger')
                    ->authorize(
                        fn (User $record): bool =>
                            Gate::forUser(Filament::auth()->user())
                                ->allows('removeCompanyAccess', $record)
                    )
                    ->requiresConfirmation()
                    ->modalHeading(__('Retirar acceso a esta empresa'))
                    ->modalSubmitActionLabel(__('Retirar acceso'))
                    ->modalDescription(__(
                        'El usuario perderá el acceso a esta empresa. Su cuenta y sus accesos a otras empresas se conservarán.'
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
                            app(UserManagementService::class)->removeCompanyAccess(
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
                            ->title(__('Acceso retirado'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}