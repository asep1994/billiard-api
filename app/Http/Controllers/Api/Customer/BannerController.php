<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Models\Banner;

/**
 * Public listing of active homepage banners - no authentication required.
 */
class BannerController extends Controller
{
    public function index()
    {
        $banners = Banner::where('is_active', true)->orderBy('order')->get();

        return BannerResource::collection($banners);
    }
}
