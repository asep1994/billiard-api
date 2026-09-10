<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdatePartnerLeadRequest;
use App\Http\Resources\PartnerLeadResource;
use App\Models\PartnerLead;
use Illuminate\Http\Request;

class PartnerLeadController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(PartnerLead::class, 'partner_lead');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $leads = PartnerLead::with('customerAccount')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest()
            ->paginate($perPage);

        return PartnerLeadResource::collection($leads);
    }

    /**
     * Display the specified resource.
     */
    public function show(PartnerLead $partnerLead)
    {
        return new PartnerLeadResource($partnerLead->load('customerAccount'));
    }

    /**
     * Update the specified resource in storage - used to move a lead
     * through its follow-up pipeline (new -> contacted -> converted/rejected).
     */
    public function update(UpdatePartnerLeadRequest $request, PartnerLead $partnerLead)
    {
        $partnerLead->update($request->validated());

        return new PartnerLeadResource($partnerLead);
    }
}
