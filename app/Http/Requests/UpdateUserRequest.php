<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        $model = $this->route('user');

        $assignableRoles = $this->user()->isSuperAdmin()
            ? UserRole::cases()
            : [UserRole::VendorAdmin, UserRole::Staff];

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($model)],
            'password' => ['sometimes', 'confirmed', Password::defaults()],
            'role' => ['sometimes', Rule::in(array_map(fn (UserRole $role) => $role->value, $assignableRoles))],
        ];
    }
}
