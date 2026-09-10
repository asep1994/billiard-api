<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ForgotPasswordRequest;
use App\Http\Requests\Customer\RegisterCustomerRequest;
use App\Http\Requests\Customer\ResetPasswordRequest;
use App\Http\Requests\Customer\UpdateCustomerProfileRequest;
use App\Http\Resources\CustomerAccountResource;
use App\Models\CustomerAccount;
use App\Models\CustomerPasswordResetCode;
use App\Notifications\Customer\PasswordResetCode as PasswordResetCodeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $account = CustomerAccount::where('email', $credentials['email'])->first();

        if (! $account || ! Hash::check($credentials['password'], $account->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
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

    /**
     * Update the authenticated customer's own name/email/phone, and
     * optionally their password (requires the current password to match).
     */
    public function updateProfile(UpdateCustomerProfileRequest $request): CustomerAccountResource
    {
        $account = $request->user();
        $data = $request->validated();

        if (! empty($data['password'])) {
            $account->password = Hash::make($data['password']);
        }

        $account->name = $data['name'];
        $account->email = $data['email'];
        $account->phone = $data['phone'];
        $account->save();

        return new CustomerAccountResource($account);
    }

    /**
     * Email a one-time code the customer can use to reset their password.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $account = CustomerAccount::where('email', $request->validated('email'))->first();

        if (! $account) {
            return response()->json(['message' => 'Email tidak terdaftar.'], Response::HTTP_NOT_FOUND);
        }

        $code = (string) random_int(100000, 999999);

        $account->passwordResetCodes()->create([
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        Notification::send($account, new PasswordResetCodeNotification($code));

        return response()->json([
            'message' => 'Kode reset password telah dikirim ke email kamu.',
        ]);
    }

    /**
     * Reset the password for the account matching the given email, provided
     * a still-valid code sent via forgotPassword() is supplied.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $account = CustomerAccount::where('email', $request->validated('email'))->first();

        if (! $account) {
            throw ValidationException::withMessages(['email' => ['Email tidak terdaftar.']]);
        }

        $resetCode = $account->passwordResetCodes()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest()
            ->get()
            ->first(fn (CustomerPasswordResetCode $row) => Hash::check($request->validated('code'), $row->code));

        if (! $resetCode) {
            throw ValidationException::withMessages(['code' => ['Kode salah atau sudah kadaluarsa.']]);
        }

        $account->update(['password' => Hash::make($request->validated('password'))]);
        $resetCode->update(['used_at' => now()]);
        $account->tokens()->delete();

        return response()->json(['message' => 'Password berhasil direset. Silakan masuk dengan password baru.']);
    }
}
