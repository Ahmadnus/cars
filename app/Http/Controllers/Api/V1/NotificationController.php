<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationResource;
use App\Models\DeviceToken;
use App\Services\PushService;
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
        $data = $request->validate([
            // Provider tokens are long — FCM's run past 160 characters and the
            // format is not guaranteed, so the column is generous and the
            // uniqueness is enforced on a hash of it.
            'push_token' => ['required', 'string', 'max:512'],
            // `web` is the dashboard: an administrator gets the same booking and
            // registration alerts in the browser that staff get on a phone.
            'platform' => ['required', 'in:android,ios,web'],
            'app' => ['nullable', 'in:trainee,trainer,dashboard'],
        ], [], [
            'push_token' => 'رمز الجهاز',
            'platform' => 'نوع الجهاز',
        ]);

        DeviceToken::register(
            $request->user(),
            $data['push_token'],
            $data['platform'],
            $data['app'] ?? 'trainee',
        );

        return $this->ok(
            ['push_enabled' => app(PushService::class)->isEnabled()],
            'تم تسجيل الجهاز للإشعارات.',
        );
    }

    /**
     * Forget this device.
     *
     * Called on sign-out: leaving the token behind would send the next person's
     * messages to a phone that is no longer signed in.
     */
    public function forgetDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string', 'max:512'],
        ], [], ['push_token' => 'رمز الجهاز']);

        DeviceToken::where('token_hash', hash('sha256', $data['push_token']))
            ->where('user_id', $request->user()->id)
            ->delete();

        return $this->ok(message: 'تم إلغاء تسجيل الجهاز.');
    }
}
