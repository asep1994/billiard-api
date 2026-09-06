<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
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
        $assignableRoles = $this->user()->isSuperAdmin()
            ? UserRole::cases()
            : [UserRole::VendorAdmin, UserRole::Staff];

        return [
            'vendor_id' => [
                Rule::requiredIf($this->user()->isSuperAdmin() && $this->input('role') !== UserRole::SuperAdmin->value),
                'nullable', 'integer', 'exists:vendors,id',
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(array_map(fn (UserRole $role) => $role->value, $assignableRoles))],
        ];
    }
}
