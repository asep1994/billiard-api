<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $perPage = min($request->integer('per_page', 15), 100);

        $notifications = NotificationResource::collection(
            $request->user()->notifications()->paginate($perPage)
        );

        return $notifications->additional([
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Mark the given notification as read.
     */
    public function markAsRead(Request $request, string $notification)
    {
        $model = $request->user()->notifications()->findOrFail($notification);
        $model->markAsRead();

        return new NotificationResource($model);
    }

    /**
     * Mark all of the user's notifications as read.
     */
    public function markAllAsRead(Request $request)
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->noContent();
    }
}
