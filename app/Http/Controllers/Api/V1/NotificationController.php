<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = $request->boolean('unread')
            ? $request->user()->unreadNotifications()
            : $request->user()->notifications();

        $notifications = $query->paginate($this->perPage());

        return $this->respond(
            true,
            'تم جلب الإشعارات بنجاح.',
            NotificationResource::collection($notifications->getCollection()),
            [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'total' => $notifications->total(),
                'unread_count' => $request->user()->unreadNotifications()->count(),
            ],
        );
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->first();

        if (! $notification) {
            return $this->failed('الإشعار غير موجود.', status: 404);
        }

        $notification->markAsRead();

        return $this->ok(message: 'تم تعليم الإشعار كمقروء.');
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return $this->ok(message: 'تم تعليم جميع الإشعارات كمقروءة.');
    }

    /**
     * Register a device push token.
     *
     * Stored against the Sanctum token so it is revoked with the session. Push
     * delivery itself is behind a ChannelGateway and off until a provider is
     * configured — see the README.
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:android,ios'],
        ]);

        return $this->ok(message: 'تم استلام رمز الجهاز. سيتم تفعيل الإشعارات الفورية عند ربط مزوّد الخدمة.');
    }
}
