<?php

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\UserManagementService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
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
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label(__('Email'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('roles')
                    ->label(__('Roles'))
                    ->badge()
                    ->getStateUsing(fn (User $record): array => $record->roles->modelKeys())
                    ->formatStateUsing(function (string $state, User $record): string {
                        $role = $record->roles->find($state);
                        $roleEnum = UserRole::tryFrom($role?->name);
                        return $roleEnum?->getLabel() ?? $role?->display_name ?: $role?->name ?: '';
                    })
                    ->color(function (string $state, User $record): array {
                        $role = $record->roles->find($state);
                        $color = $role->color ?: '#'.substr(hash('sha256', (string) $role->getKey()), 0, 6);

                        return Color::hex($color);
                    })
                    ->width('1%'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('removeCompanyAccess')
                    ->label(__('Remove Access'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->authorize(
                        fn (User $record): bool => Gate::forUser(Filament::auth()->user())
                            ->allows('removeCompanyAccess', $record)
                    )
                    ->requiresConfirmation()
                    ->modalHeading(__('Remove Access to This Company'))
                    ->modalSubmitActionLabel(__('Remove Access'))
                    ->modalDescription(__(
                        'The user will lose access to this company. Their account and access to other companies will be preserved.'
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
                            ->title(__('Access Removed'))
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
