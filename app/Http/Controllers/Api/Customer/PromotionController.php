<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

/**
 * Public listing of currently redeemable promotions, for the "Promo
 * Spesial" section of the customer app - no authentication required.
 */
class PromotionController extends Controller
{
    public function index()
    {
        $now = Carbon::now();

        $promotions = Promotion::where('is_active', true)
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->with('vendor')
            ->latest()
            ->get();

        return PromotionResource::collection($promotions);
    }
}
