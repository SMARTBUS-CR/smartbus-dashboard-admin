<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CatalogueIndexRequest extends FormRequest
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
            'page' => ['sometimes', 'array'],
            'page.size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page.number' => ['sometimes', 'integer', 'min:1'],
            'filter' => ['sometimes', 'array'],
        ];
    }
}
