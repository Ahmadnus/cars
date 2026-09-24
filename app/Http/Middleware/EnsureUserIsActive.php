<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a deactivated or locked account from continuing on a session or token
 * issued before it was disabled.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (! $user->isActive() || $user->isLocked())) {
            $message = $user->isLocked()
                ? 'تم إيقاف الحساب مؤقتاً بسبب محاولات دخول فاشلة. حاول لاحقاً.'
                : 'تم تعطيل هذا الحساب. يرجى مراجعة إدارة النظام.';

            if ($request->expectsJson()) {
                // Revoke the presented token so it cannot be replayed.
                $user->currentAccessToken()?->delete();

                return response()->json(['success' => false, 'message' => $message], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => $message]);
        }

        return $next($request);
    }
}
