<?php

namespace App\Http\Requests;

use App\Enums\TableStatus;
use App\Enums\TableType;
use App\Models\Venue;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBilliardTableRequest extends FormRequest
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
        $venueIds = $this->user()->isSuperAdmin()
            ? Venue::query()
            : Venue::where('vendor_id', $this->user()->vendor_id);

        return [
            'venue_id' => ['required', 'integer', Rule::in($venueIds->pluck('id'))],
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('billiard_tables', 'name')->where('venue_id', $this->input('venue_id')),
            ],
            'type' => ['required', Rule::enum(TableType::class)],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::enum(TableStatus::class)],
        ];
    }
}
