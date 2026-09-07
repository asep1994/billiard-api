<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\CustomerDeviceToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DeviceTokenController extends Controller
{
    /**
     * Register (or re-associate) this device's FCM token with the
     * authenticated customer. Upserts by token so a device that logs into a
     * different account migrates its token to the new owner.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'platform' => ['nullable', 'string'],
        ]);

        CustomerDeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'customer_account_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'android',
            ],
        );

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Stop sending pushes to this device, e.g. on logout.
     */
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);

        CustomerDeviceToken::where('token', $validated['token'])->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
