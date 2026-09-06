<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DuitkuCallbackRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * This endpoint is public (called by Duitku's servers, not an
     * authenticated user); the signature check in the controller is
     * what actually authenticates the request.
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
            'merchantCode' => ['required', 'string'],
            'amount' => ['required', 'numeric'],
            'merchantOrderId' => ['required', 'string'],
            'resultCode' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'reference' => ['nullable', 'string'],
            'paymentCode' => ['nullable', 'string'],
        ];
    }
}
