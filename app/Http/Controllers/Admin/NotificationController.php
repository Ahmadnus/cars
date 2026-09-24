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
