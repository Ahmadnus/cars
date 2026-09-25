<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $query = $request->user()->notifications();

        if ($request->input('filter') === 'unread') {
            $query = $request->user()->unreadNotifications();
        }

        return view('admin.notifications.index', [
            'notifications' => $query->paginate(25)->withQueryString(),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
            'filter' => $request->input('filter', 'all'),
        ]);
    }

    /**
     * The bell's feed: an unread count and the newest few.
     *
     * A session-authenticated web route rather than the API one. The dashboard
     * has a session cookie, and reaching the API with it depends on Sanctum's
     * stateful-domain configuration matching the host exactly — a setting that
     * silently returns 401 when it does not. The bell is not worth that coupling.
     */
    public function feed(Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'items' => $user->notifications()
                ->latest()
                ->limit(5)
                ->get()
                ->map(fn ($notification) => [
                    'id' => $notification->id,
                    'title' => $notification->data['title'] ?? '',
                    'body' => $notification->data['body'] ?? '',
                    'level' => $notification->data['level'] ?? 'info',
                    'url' => $notification->data['url'] ?? null,
                    'read' => $notification->read_at !== null,
                    'at' => $notification->created_at?->toIso8601String(),
                ]),
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        // Follow the notification's own link when it has one.
        $url = $record->data['url'] ?? null;

        return $url ? redirect($url) : back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back()->with('toast', ['type' => 'success', 'message' => 'تم تعليم جميع الإشعارات كمقروءة.']);
    }
}
