<?php

namespace App\Services;

use App\Enums\CompanyStatus;
use App\Models\Bus;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(
        User $actor,
        Company $company,
        array $data,
    ): Bus {
        $previousTeam = getPermissionsTeamId();

        try {
            setPermissionsTeamId($company->getKey());

            $actor->unsetRelation('roles')->unsetRelation('permissions');

            Gate::forUser($actor)->authorize(
                'create',
                [Bus::class, $company],
            );

            if ($company->status !== CompanyStatus::ACTIVE) {
                throw ValidationException::withMessages([
                    'company' => __('An active company context is required.'),
                ]);
            }

            Validator::make($data, [
                'id' => ['missing'],
                'company_id' => ['missing'],
                'is_active' => ['missing'],
                'created_at' => ['missing'],
                'updated_at' => ['missing'],
                'deleted_at' => ['missing'],
            ])->validate();

            $normalized = [
                'country_code' => isset($data['country_code'])
                    && is_string($data['country_code'])
                        ? strtoupper(trim($data['country_code']))
                        : ($data['country_code'] ?? null),
                'plate_number' => isset($data['plate_number'])
                    && is_string($data['plate_number'])
                        ? strtoupper(trim($data['plate_number']))
                        : ($data['plate_number'] ?? null),
                'unit_number' => isset($data['unit_number'])
                    && is_string($data['unit_number'])
                        ? trim($data['unit_number'])
                        : ($data['unit_number'] ?? null),
                'capacity' => $data['capacity'] ?? null,
                'brand' => isset($data['brand']) && is_string($data['brand'])
                    ? trim($data['brand'])
                    : ($data['brand'] ?? null),
                'model' => isset($data['model']) && is_string($data['model'])
                    ? trim($data['model'])
                    : ($data['model'] ?? null),
                'year' => $data['year'] ?? null,
            ];

            Validator::make($normalized, [
                'country_code' => [
                    'required',
                    'string',
                    'size:2',
                    Rule::in(array_keys(config('countries'))),
                ],
                'plate_number' => ['required', 'string', 'max:30'],
                'unit_number' => ['required', 'string', 'max:50'],
                'capacity' => ['required', 'integer', 'between:1,32767'],
                'brand' => ['nullable', 'string', 'max:100'],
                'model' => ['nullable', 'string', 'max:100'],
                'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            ])->validate();

            Validator::make($normalized, [
                'plate_number' => [
                    Rule::unique(Bus::class, 'plate_number')
                        ->where('country_code', $normalized['country_code']),
                ],
                'unit_number' => [
                    Rule::unique(Bus::class, 'unit_number')
                        ->where('company_id', $company->getKey()),
                ],
            ])->validate();

            try {
                return (new Bus)->getConnection()->transaction(fn (): Bus => Bus::query()->create([
                    'company_id' => $company->getKey(),
                    ...$normalized,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                $detail = $exception->errorInfo[2] ?? '';

                $field = match (true) {
                    str_contains($detail, '"buses_country_code_plate_number_unique"') => 'plate_number',
                    str_contains($detail, '"buses_company_id_unit_number_unique"') => 'unit_number',
                    default => null,
                };

                if ($field === null) {
                    throw $exception;
                }

                throw ValidationException::withMessages([
                    $field => __('validation.unique', [
                        'attribute' => __('validation.attributes.'.$field),
                    ]),
                ]);
            }
        } finally {
            setPermissionsTeamId($previousTeam);

            $actor->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
