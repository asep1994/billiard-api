<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePayoutRequest;
use App\Http\Resources\PayoutResource;
use App\Models\ActivityLog;
use App\Models\Payout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PayoutController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Payout::class, 'payout');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $payouts = $request->user()->isSuperAdmin()
            ? Payout::query()
            : Payout::where('vendor_id', $request->user()->vendor_id);

        if ($request->filled('vendor_id') && $request->user()->isSuperAdmin()) {
            $payouts->where('vendor_id', $request->integer('vendor_id'));
        }

        $perPage = min($request->integer('per_page', 15), 100);

        return PayoutResource::collection(
            $payouts->with(['vendor', 'user'])->latest('paid_at')->paginate($perPage)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePayoutRequest $request)
    {
        $data = $request->validated();
        $data['user_id'] = $request->user()->id;
        $data['paid_at'] ??= now();

        $payout = Payout::create($data)->load(['vendor', 'user']);

        ActivityLog::record(
            ActivityAction::Payout,
            'payout',
            $payout->id,
            "{$request->user()->name} mencairkan payout Rp".number_format((float) $payout->amount, 0, ',', '.')." untuk {$payout->vendor->name}",
            $payout->vendor_id,
        );

        return (new PayoutResource($payout))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
