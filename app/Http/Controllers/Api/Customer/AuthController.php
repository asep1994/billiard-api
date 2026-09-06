<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\RegisterCustomerRequest;
use App\Http\Resources\CustomerAccountResource;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Register a new platform-wide customer account.
     */
    public function register(RegisterCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        $account = CustomerAccount::create($data);
        $token = $account->createToken('customer-app')->plainTextToken;

        return response()->json([
            'customer' => new CustomerAccountResource($account),
            'token' => $token,
        ], 201);
    }

    /**
     * Authenticate a customer account and issue a personal access token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $account = CustomerAccount::where('phone', $credentials['phone'])->first();

        if (! $account || ! Hash::check($credentials['password'], $account->password)) {
            throw ValidationException::withMessages([
                'phone' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $account->createToken('customer-app')->plainTextToken;

        return response()->json([
            'customer' => new CustomerAccountResource($account),
            'token' => $token,
        ]);
    }

    /**
     * Revoke the token used to authenticate the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(status: 204);
    }

    /**
     * Get the authenticated customer account.
     */
    public function me(Request $request): CustomerAccountResource
    {
        return new CustomerAccountResource($request->user());
    }
}
