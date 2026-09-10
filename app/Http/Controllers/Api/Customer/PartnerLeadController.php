<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\LeadStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StorePartnerLeadRequest;
use App\Http\Resources\PartnerLeadResource;
use App\Models\PartnerLead;
use App\Models\User;
use App\Notifications\NewPartnerLead;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Notification;

class PartnerLeadController extends Controller
{
    /**
     * Record a customer's "Ajak Gabung" tap on a non-partnered venue found
     * via Google Places. Submitting the same place twice just returns the
     * existing lead instead of erroring - the customer already did their
     * part the first time.
     */
    public function store(StorePartnerLeadRequest $request)
    {
        $data = $request->validated();

        $lead = PartnerLead::firstOrCreate(
            ['google_place_id' => $data['google_place_id']],
            [...$data, 'customer_account_id' => $request->user()->id, 'status' => LeadStatus::New],
        );

        if ($lead->wasRecentlyCreated) {
            Notification::send(User::where('role', UserRole::SuperAdmin)->get(), new NewPartnerLead($lead));
        }

        return (new PartnerLeadResource($lead))->response()->setStatusCode(Response::HTTP_CREATED);
    }
}
