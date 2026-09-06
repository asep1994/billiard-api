<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
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
        $vendorId = $this->user()->isSuperAdmin() ? $this->integer('vendor_id') : $this->user()->vendor_id;

        return [
            'vendor_id' => [Rule::requiredIf($this->user()->isSuperAdmin()), 'integer', 'exists:vendors,id'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'phone')->where('vendor_id', $vendorId),
            ],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
