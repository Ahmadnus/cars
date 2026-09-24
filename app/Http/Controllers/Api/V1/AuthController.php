<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Token authentication for the Trainer and Trainee apps.
 *
 * Uses the same account records and the same lockout rules as the dashboard —
 * a user disabled in the admin UI immediately loses API access too.
 */
class AuthController extends ApiController
{
    protected const MAX_ATTEMPTS = 5;

    protected const LOCK_MINUTES = 15;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:phone', 'nullable', 'email'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
        ], [], [
            'email' => 'البريد الإلكتروني',
            'phone' => 'رقم الهاتف',
            'password' => 'كلمة المرور',
            'device_name' => 'اسم الجهاز',
        ]);

        $key = 'api-login:'.($data['email'] ?? $data['phone']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->failed(
                'محاولات كثيرة جداً. حاول بعد '.ceil(RateLimiter::availableIn($key) / 60).' دقيقة.',
                status: 429,
            );
        }

        $user = User::query()
            ->when(! empty($data['email']), fn ($q) => $q->where('email', $data['email']))
            ->when(! empty($data['phone']), fn ($q) => $q->where('phone', $data['phone']))
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, self::LOCK_MINUTES * 60);
            $this->record($user, $request, 'failed', $data['email'] ?? $data['phone']);

            return $this->failed('بيانات الدخول غير صحيحة.', ['email' => ['بيانات الدخول غير صحيحة.']], 401);
        }

        if ($user->isLocked()) {
            $this->record($user, $request, 'locked');

            return $this->failed('تم إيقاف الحساب مؤقتاً بسبب محاولات دخول فاشلة.', status: 423);
        }

        if (! $user->isActive()) {
            $this->record($user, $request, 'failed');

            return $this->failed('هذا الحساب غير مفعّل.', status: 403);
        }

        RateLimiter::clear($key);

        // One token per device name: logging in again from the same device
        // replaces its token instead of accumulating stale ones.
        $user->tokens()->where('name', $data['device_name'])->delete();
        $token = $user->createToken($data['device_name'], $this->abilitiesFor($user));

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();

        $this->record($user, $request, 'success');

        return $this->ok([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user->load(['roles', 'branch', 'trainer', 'trainee'])),
        ], 'تم تسجيل الدخول بنجاح.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(
            new UserResource($request->user()->load(['roles.permissions', 'branch', 'trainer', 'trainee'])),
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $this->record($request->user(), $request, 'logout');
        $request->user()->currentAccessToken()?->delete();

        return $this->ok(message: 'تم تسجيل الخروج بنجاح.');
    }

    /** Revoke every token for this account, across all devices. */
    public function logoutAll(Request $request): JsonResponse
    {
        $this->record($request->user(), $request, 'logout');
        $request->user()->tokens()->delete();

        return $this->ok(message: 'تم تسجيل الخروج من جميع الأجهزة.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()],
        ], [
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        // Other devices must re-authenticate with the new password.
        $current = $request->user()->currentAccessToken();
        $request->user()->tokens()->whereKeyNot($current?->id)->delete();

        return $this->ok(message: 'تم تغيير كلمة المرور. تم تسجيل الخروج من الأجهزة الأخرى.');
    }

    /**
     * Token abilities mirror the user's permissions, so a stolen trainer token
     * can never be used to reach an accountant's endpoints.
     *
     * @return array<int, string>
     */
    protected function abilitiesFor(User $user): array
    {
        return $user->isSuperAdmin() ? ['*'] : $user->permissionNames()->all();
    }

    protected function record(?User $user, Request $request, string $result, ?string $identifier = null): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $identifier ?? $user?->email,
            'result' => $result,
            'guard' => 'sanctum',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
