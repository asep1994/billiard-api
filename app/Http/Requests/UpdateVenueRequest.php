<?php

namespace App\Http\Requests;

use App\Enums\Status;
use App\Enums\VenueFacility;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueRequest extends FormRequest
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
        $venue = $this->route('venue');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'string', 'max:255', 'alpha_dash',
                Rule::unique('venues', 'slug')->where('vendor_id', $venue->vendor_id)->ignore($venue),
            ],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'description' => ['nullable', 'string'],
            'facilities' => ['nullable', 'array'],
            'facilities.*' => [Rule::enum(VenueFacility::class)],
            'phone' => ['nullable', 'string', 'max:30'],
            'opening_time' => ['nullable', 'date_format:H:i'],
            'closing_time' => ['nullable', 'date_format:H:i', 'after:opening_time'],
            'status' => ['sometimes', Rule::enum(Status::class)],
        ];
    }
}
