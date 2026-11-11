<?php

namespace App\Http\Requests\Api\V1\Operations;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Validator;

class StopIndexRequest extends CatalogueIndexRequest
{
    /**
     * Accept bbox=west,south,east,north and near=latitude,longitude,radius_m.
     */
    protected function prepareForValidation(): void
    {
        $filters = $this->input('filter');
        if (! is_array($filters)) {
            return;
        }

        foreach (['bbox', 'near'] as $name) {
            if (isset($filters[$name]) && is_string($filters[$name])) {
                $filters[$name] = explode(',', $filters[$name]);
            }
        }
        $this->merge(['filter' => $filters]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'filter.bbox' => ['sometimes', 'array', 'list', 'size:4'],
            'filter.bbox.0' => ['required_with:filter.bbox', 'numeric', 'between:-180,180'],
            'filter.bbox.1' => ['required_with:filter.bbox', 'numeric', 'between:-90,90'],
            'filter.bbox.2' => ['required_with:filter.bbox', 'numeric', 'between:-180,180'],
            'filter.bbox.3' => ['required_with:filter.bbox', 'numeric', 'between:-90,90'],
            'filter.near' => ['sometimes', 'array', 'list', 'size:3'],
            'filter.near.0' => ['required_with:filter.near', 'numeric', 'between:-90,90'],
            'filter.near.1' => ['required_with:filter.near', 'numeric', 'between:-180,180'],
            'filter.near.2' => ['required_with:filter.near', 'numeric', 'min:1', 'max:2000'],
        ];
    }

    /** @return array<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('filter.bbox')) {
                return;
            }

            [$west, $south, $east, $north] = array_map('floatval', $this->input('filter.bbox'));
            if ($west >= $east || $south >= $north || $east - $west > 1 || $north - $south > 1) {
                $validator->errors()->add('filter.bbox', __('The bounding box must be ordered west, south, east, north and span at most one degree per axis.'));
            }
        }];
    }
}
