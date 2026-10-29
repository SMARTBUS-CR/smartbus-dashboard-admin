<?php

namespace App\Filament\Resources\Stops\Pages;

use App\Filament\Resources\Stops\StopResource;
use App\Models\Company;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;

class CreateStop extends CreateRecord
{
    protected static string $resource = StopResource::class;

    protected ?bool $hasUnsavedDataChangesAlert = true;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $company = Filament::getTenant();

        abort_unless(
            $company instanceof Company
            && Filament::auth()->user()?->canAccessTenant($company),
            403,
        );

        $isShared = (Filament::auth()->user()?->isSuperAdmin() ?? false)
            && ($data['is_shared'] ?? false);

        return [
            'company_id' => $isShared ? null : $company->getKey(),
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResourceUrl('index');
    }
}
