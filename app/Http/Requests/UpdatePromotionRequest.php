<?php

namespace App\Http\Requests;

use App\Enums\PromotionType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionRequest extends FormRequest
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
        $promotion = $this->route('promotion');

        return [
            'code' => [
                'sometimes', 'string', 'max:30', 'alpha_dash',
                Rule::unique('promotions', 'code')->where('vendor_id', $promotion->vendor_id)->ignore($promotion),
            ],
            'type' => ['sometimes', Rule::enum(PromotionType::class)],
            'value' => ['sometimes', 'numeric', 'min:0'],
            'max_discount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
