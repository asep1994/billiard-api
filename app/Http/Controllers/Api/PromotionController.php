<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StorePromotionRequest;
use App\Http\Requests\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Models\ActivityLog;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PromotionController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Promotion::class, 'promotion');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $promotions = $request->user()->isSuperAdmin()
            ? Promotion::query()
            : Promotion::where('vendor_id', $request->user()->vendor_id);

        $perPage = min($request->integer('per_page', 15), 100);

        return PromotionResource::collection($promotions->latest()->paginate($perPage));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePromotionRequest $request)
    {
        $data = $request->validated();
        $data['vendor_id'] = $request->user()->isSuperAdmin() ? $data['vendor_id'] : $request->user()->vendor_id;

        $promotion = Promotion::create($data)->refresh();

        ActivityLog::record(
            ActivityAction::Created,
            'promotion',
            $promotion->id,
            "{$request->user()->name} membuat promo {$promotion->code}",
            $promotion->vendor_id,
        );

        return (new PromotionResource($promotion))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Promotion $promotion)
    {
        return new PromotionResource($promotion);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePromotionRequest $request, Promotion $promotion)
    {
        $promotion->update($request->validated());

        return new PromotionResource($promotion);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Promotion $promotion)
    {
        $vendorId = $promotion->vendor_id;
        $code = $promotion->code;

        $promotion->delete();

        ActivityLog::record(
            ActivityAction::Deleted,
            'promotion',
            null,
            "{$request->user()->name} menghapus promo {$code}",
            $vendorId,
        );

        return response()->noContent();
    }
}
