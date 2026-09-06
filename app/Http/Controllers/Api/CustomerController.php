<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Requests\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\ActivityLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(Customer::class, 'customer');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $customers = $request->user()->isSuperAdmin()
            ? Customer::query()
            : Customer::where('vendor_id', $request->user()->vendor_id);

        $perPage = min($request->integer('per_page', 15), 100);

        return CustomerResource::collection($customers->paginate($perPage));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCustomerRequest $request)
    {
        $data = $request->validated();
        $data['vendor_id'] = $request->user()->isSuperAdmin() ? $data['vendor_id'] : $request->user()->vendor_id;

        $customer = Customer::create($data);

        ActivityLog::record(
            ActivityAction::Created,
            'customer',
            $customer->id,
            "{$request->user()->name} menambahkan pelanggan {$customer->name}",
            $customer->vendor_id,
        );

        return (new CustomerResource($customer))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(Customer $customer)
    {
        return new CustomerResource($customer);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer)
    {
        $customer->update($request->validated());

        return new CustomerResource($customer);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Customer $customer)
    {
        $vendorId = $customer->vendor_id;
        $name = $customer->name;

        $customer->delete();

        ActivityLog::record(
            ActivityAction::Deleted,
            'customer',
            null,
            "{$request->user()->name} menghapus pelanggan {$name}",
            $vendorId,
        );

        return response()->noContent();
    }
}
