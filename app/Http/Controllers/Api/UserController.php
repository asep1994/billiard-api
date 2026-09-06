<?php

namespace App\Http\Controllers\Api;

use App\Enums\ActivityAction;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct()
    {
        $this->authorizeResource(User::class, 'user');
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $users = $request->user()->isSuperAdmin()
            ? User::query()
            : User::where('vendor_id', $request->user()->vendor_id);

        return UserResource::collection($users->paginate());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request)
    {
        $data = $request->validated();
        $data['password'] = Hash::make($data['password']);

        if (! $request->user()->isSuperAdmin()) {
            $data['vendor_id'] = $request->user()->vendor_id;
        }

        if ($data['role'] === UserRole::SuperAdmin->value) {
            $data['vendor_id'] = null;
        }

        $user = User::create($data);

        ActivityLog::record(
            ActivityAction::Created,
            'user',
            $user->id,
            "{$request->user()->name} menambahkan pengguna {$user->name} ({$user->role->value})",
            $user->vendor_id,
        );

        return (new UserResource($user))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user)
    {
        return new UserResource($user->load('vendor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user)
    {
        $data = $request->validated();

        if (isset($data['password'])) {
            $data['password'] = Hash::make($data['password']);
        }

        $user->update($data);

        return new UserResource($user);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, User $user)
    {
        $vendorId = $user->vendor_id;
        $name = $user->name;

        $user->delete();

        ActivityLog::record(
            ActivityAction::Deleted,
            'user',
            null,
            "{$request->user()->name} menghapus pengguna {$name}",
            $vendorId,
        );

        return response()->noContent();
    }
}
