<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBilliardTableRequest;
use App\Http\Requests\UpdateBilliardTableRequest;
use App\Http\Resources\BilliardTableResource;
use App\Models\BilliardTable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BilliardTableController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(BilliardTable::class, 'table');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $tables = $request->user()->isSuperAdmin()
            ? BilliardTable::query()
            : BilliardTable::whereHas('venue', fn ($query) => $query->where('vendor_id', $request->user()->vendor_id));

        return BilliardTableResource::collection($tables->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBilliardTableRequest $request)
    {
        $table = BilliardTable::create($request->validated())->refresh();

        return (new BilliardTableResource($table))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(BilliardTable $table)
    {
        return new BilliardTableResource($table);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBilliardTableRequest $request, BilliardTable $table)
    {
        $table->update($request->validated());

        return new BilliardTableResource($table);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BilliardTable $table)
    {
        $table->delete();

        return response()->noContent();
    }
}
