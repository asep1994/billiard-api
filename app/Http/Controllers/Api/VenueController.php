<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVenueRequest;
use App\Http\Requests\UpdateVenueRequest;
use App\Http\Requests\UploadVenuePhotoRequest;
use App\Http\Resources\VenueResource;
use App\Models\Venue;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class VenueController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Venue::class, 'venue');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $venues = $request->user()->isSuperAdmin()
            ? Venue::query()
            : Venue::where('vendor_id', $request->user()->vendor_id);

        $perPage = min($request->integer('per_page', 15), 100);

        return VenueResource::collection($venues->paginate($perPage));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreVenueRequest $request)
    {
        $data = $request->validated();
        $data['vendor_id'] = $request->user()->isSuperAdmin() ? $data['vendor_id'] : $request->user()->vendor_id;

        $venue = Venue::create($data)->refresh();

        return (new VenueResource($venue))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Venue $venue)
    {
        return new VenueResource($venue->load('vendor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateVenueRequest $request, Venue $venue)
    {
        $venue->update($request->validated());

        return new VenueResource($venue);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Venue $venue)
    {
        $venue->delete();

        return response()->noContent();
    }

    /**
     * Upload (or replace) the venue's cover photo.
     */
    public function uploadPhoto(UploadVenuePhotoRequest $request, Venue $venue)
    {
        $this->authorize('update', $venue);

        if ($venue->photo_path) {
            Storage::disk('public')->delete($venue->photo_path);
        }

        $venue->update([
            'photo_path' => $request->file('photo')->store('venues', 'public'),
        ]);

        return new VenueResource($venue);
    }
}
