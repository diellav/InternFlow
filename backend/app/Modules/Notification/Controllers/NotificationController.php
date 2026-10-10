<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Requests\ListNotificationsRequest;
use App\Modules\Notification\Requests\ReadNotificationRequest;
use App\Modules\Notification\Resources\NotificationResource;
use App\Modules\Notification\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    public function index(ListNotificationsRequest $request): AnonymousResourceCollection
    {
        return NotificationResource::collection($this->notifications->paginate($request->user(), $request->validated()));
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['data' => ['unread_count' => $this->notifications->unreadCount($request->user())]]);
    }

    public function read(ReadNotificationRequest $request, string $notification): NotificationResource
    {
        return new NotificationResource($this->notifications->read($request->user(), $notification));
    }

    public function readAll(ReadNotificationRequest $request): JsonResponse
    {
        return response()->json(['data' => ['updated_count' => $this->notifications->readAll($request->user())]]);
    }
}
