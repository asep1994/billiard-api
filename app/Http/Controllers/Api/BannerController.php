<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBannerRequest;
use App\Http\Requests\UpdateBannerRequest;
use App\Http\Resources\BannerResource;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Banner::class, 'banner');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 15), 100);

        return BannerResource::collection(
            Banner::orderBy('order')->latest()->paginate($perPage)
        );
    }

    /**
     * Store a newly created resource in storage - images are only ever set
     * here, never replaced on update, so there's no multipart-on-PUT
     * workaround to deal with (see VenueController::uploadPhoto for why that
     * matters: PHP doesn't parse multipart bodies on PUT requests).
     */
    public function store(StoreBannerRequest $request)
    {
        $data = $request->validated();
        $data['image_path'] = $request->file('image')->store('banners', 'public');
        $data['order'] ??= ((int) Banner::max('order')) + 1;
        unset($data['image']);

        $banner = Banner::create($data);

        return (new BannerResource($banner))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Banner $banner)
    {
        return new BannerResource($banner);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBannerRequest $request, Banner $banner)
    {
        $banner->update($request->validated());

        return new BannerResource($banner);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Banner $banner)
    {
        if ($banner->image_path) {
            Storage::disk('public')->delete($banner->image_path);
        }

        $banner->delete();

        return response()->noContent();
    }
}
