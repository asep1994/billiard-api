<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;

/**
 * Small set of platform-wide values the customer app needs to render a
 * checkout summary before a booking exists (e.g. the service fee that will
 * be added), so those numbers never drift from what actually gets charged.
 */
class ConfigController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => [
                'service_fee' => config('booking.service_fee'),
            ],
        ]);
    }
}
