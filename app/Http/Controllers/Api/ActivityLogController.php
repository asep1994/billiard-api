<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        abort_unless(
            $request->user()->isSuperAdmin() || $request->user()->isVendorAdmin(),
            403,
            'You are not authorized to view activity logs.',
        );

        $logs = $request->user()->isSuperAdmin()
            ? ActivityLog::query()
            : ActivityLog::where('vendor_id', $request->user()->vendor_id);

        $perPage = min($request->integer('per_page', 15), 100);

        return ActivityLogResource::collection(
            $logs->with('user')->latest()->paginate($perPage)
        );
    }
}
