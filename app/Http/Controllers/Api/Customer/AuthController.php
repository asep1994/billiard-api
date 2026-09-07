<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\ForgotPasswordRequest;
use App\Http\Requests\Customer\RegisterCustomerRequest;
use App\Http\Requests\Customer\ResetPasswordRequest;
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

    /**
     * Email a one-time code the customer can use to reset their password.
     * Only accounts with an email on file can self-service a reset - phone
     * is the login identifier but we have no SMS/WA gateway to deliver a
     * code there.
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $account = CustomerAccount::where('phone', $request->validated('phone'))->first();

        if (! $account) {
            return response()->json(['message' => 'Nomor HP tidak terdaftar.'], Response::HTTP_NOT_FOUND);
        }

        if (! $account->email) {
            return response()->json([
                'message' => 'Akun ini belum punya email terdaftar. Hubungi admin untuk reset password.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $code = (string) random_int(100000, 999999);

        $account->passwordResetCodes()->create([
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(15),
        ]);

        Notification::send($account, new PasswordResetCodeNotification($code));

        return response()->json([
            'message' => 'Kode reset password telah dikirim ke email yang terdaftar.',
        ]);
    }

    /**
     * Reset the password for the account matching the given phone number,
     * provided a still-valid code sent via forgotPassword() is supplied.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $account = CustomerAccount::where('phone', $request->validated('phone'))->first();

        if (! $account) {
            throw ValidationException::withMessages(['phone' => ['Nomor HP tidak terdaftar.']]);
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
