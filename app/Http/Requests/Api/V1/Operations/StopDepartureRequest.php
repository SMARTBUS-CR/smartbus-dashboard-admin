<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StopDepartureRequest extends FormRequest
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
            'departure_at' => ['nullable', 'date'],
            'window_minutes' => ['nullable', 'integer', 'min:1', 'max:480'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable'],
            'page.size' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page.number' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
