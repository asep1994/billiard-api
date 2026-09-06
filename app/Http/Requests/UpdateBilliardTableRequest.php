<?php

namespace App\Http\Requests;

use App\Enums\TableStatus;
use App\Enums\TableType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBilliardTableRequest extends FormRequest
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
        $table = $this->route('table');

        return [
            'name' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('billiard_tables', 'name')->where('venue_id', $table->venue_id)->ignore($table),
            ],
            'type' => ['sometimes', Rule::enum(TableType::class)],
            'hourly_rate' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::enum(TableStatus::class)],
        ];
    }
}
