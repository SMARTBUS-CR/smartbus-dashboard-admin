<?php

namespace App\Http\Requests\Api\V1\Operations;

use App\Models\Route;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreFavoriteRouteRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'route_id' => [
                'required',
                'uuid',
                'exists:routes,id',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $route = Route::inPublicCatalogue()->whereKey($value)->first();
                    if (! $route) {
                        $fail(__('The selected route is unavailable or does not belong to an active company.'));
                    }
                },
            ],
            'label' => ['nullable', 'string', 'max:50'],
        ];
    }
}
