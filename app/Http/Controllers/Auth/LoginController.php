<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginHistory;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the dashboard.
 *
 * Protection is layered: a per-IP rate limit blunts distributed guessing, and a
 * per-account counter locks an individual account after repeated failures so a
 * targeted attack cannot simply rotate IPs. Every attempt is recorded.
 */
class LoginController extends Controller
{
    protected const MAX_ATTEMPTS = 5;

    protected const LOCK_MINUTES = 15;

    public function show(): View|RedirectResponse
    {
        return Auth::check()
            ? redirect()->route('admin.dashboard')
            : view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [], [
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
        ]);

        $this->assertNotRateLimited($request);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->recordFailure($request, $user, $credentials['email']);

            // One message for both cases: naming which half was wrong would
            // confirm whether an address is registered.
            throw ValidationException::withMessages([
                'email' => 'بيانات الدخول غير صحيحة.',
            ]);
        }

        if ($user->isLocked()) {
            $this->record($user, $request, 'locked');

            throw ValidationException::withMessages([
                'email' => 'تم إيقاف الحساب مؤقتاً بسبب محاولات دخول فاشلة. حاول بعد قليل.',
            ]);
        }

        if (! $user->isActive()) {
            $this->record($user, $request, 'failed');

            throw ValidationException::withMessages([
                'email' => 'هذا الحساب غير مفعّل. يرجى مراجعة إدارة النظام.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->record($user, $request, 'success');

        // Pin the user to a branch they can actually reach.
        app(BranchContext::class)->forget();

        return redirect()->intended(route('admin.dashboard'))
            ->with('toast', ['type' => 'success', 'message' => 'مرحباً بك، '.$user->name]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user) {
            $this->record($user, $request, 'logout');
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'تم تسجيل الخروج بنجاح.');
    }

    // ------------------------------------------------------------------
    // Throttling
    // ------------------------------------------------------------------

    protected function assertNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), self::MAX_ATTEMPTS)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'email' => 'محاولات كثيرة جداً. يرجى المحاولة بعد '.ceil($seconds / 60).' دقيقة.',
        ]);
    }

    protected function recordFailure(Request $request, ?User $user, string $email): void
    {
        RateLimiter::hit($this->throttleKey($request), self::LOCK_MINUTES * 60);

        if ($user) {
            $attempts = $user->failed_login_attempts + 1;

            $user->forceFill([
                'failed_login_attempts' => $attempts,
                'locked_until' => $attempts >= self::MAX_ATTEMPTS
                    ? now()->addMinutes(self::LOCK_MINUTES)
                    : $user->locked_until,
            ])->save();
        }

        $this->record($user, $request, 'failed', $email);
    }

    protected function record(?User $user, Request $request, string $result, ?string $email = null): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $email ?? $user?->email,
            'result' => $result,
            'guard' => 'web',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    protected function throttleKey(Request $request): string
    {
        return 'login:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip();
    }
}
