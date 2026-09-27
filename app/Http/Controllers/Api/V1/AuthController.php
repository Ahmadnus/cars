<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\IssuedPassword;
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

        /*
         | Trainees may sign in with either a password or a passcode.
         |
         | Passcode-only would be the stronger design — one door per account —
         | but the Trainee app has no passcode screen yet, and the credentials
         | already issued to trainees are passwords. Closing this door before
         | that screen exists would lock every existing trainee out.
         |
         | When the app gains the passcode flow, restore the refusal here.
         */

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
        $user = $request->user();

        // A trainee or trainer typing on a phone keeps the handed-out policy
        // (letters or digits, six to eight); anyone who can reach money or other
        // people's records keeps the full one. Holding a trainee to a symbol and
        // a capital is how a password ends up written inside the car.
        $policy = $this->isAppAccount($user)
            ? ['required', 'confirmed', 'string', 'alpha_num:ascii', 'min:'.IssuedPassword::MIN_LENGTH, 'max:64']
            : ['required', 'confirmed', \Illuminate\Validation\Rules\Password::defaults()];

        $data = $request->validate([
            'current_password' => ['required', 'current_password:sanctum'],
            'password' => $policy,
        ], [
            'current_password.current_password' => 'كلمة المرور الحالية غير صحيحة.',
            'password.alpha_num' => 'كلمة المرور: أحرف إنجليزية أو أرقام فقط.',
            'password.ascii' => 'كلمة المرور: أحرف إنجليزية أو أرقام فقط.',
        ], [
            'current_password' => 'كلمة المرور الحالية',
            'password' => 'كلمة المرور الجديدة',
        ]);

        $request->user()->update(['password' => Hash::make($data['password'])]);

        // Other devices must re-authenticate with the new password.
        $current = $request->user()->currentAccessToken();
        $request->user()->tokens()->whereKeyNot($current?->id)->delete();

        return $this->ok(message: 'تم تغيير كلمة المرور. تم تسجيل الخروج من الأجهزة الأخرى.');
    }

    /**
     * "I cannot sign in" — ask the office for a new password.
     *
     * There is no automatic reset. These accounts sign in by phone; the address
     * on a phone-only account is one the office invented and nobody reads, and
     * there is no SMS gateway. So this raises a job for staff, who reset the
     * password on the person's page and hand it over on the same channel they
     * used the first time — which is also what establishes that the person
     * asking is who they say.
     *
     * Answers identically whether or not the number is registered: a different
     * reply would turn this into a way to find out who trains here.
     */
    public function requestPasswordReset(Request $request, NotificationService $notifications): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ], [], ['phone' => 'رقم الهاتف']);

        $digits = preg_replace('/\D/', '', $data['phone']) ?? '';

        if (strlen($digits) >= 9) {
            $user = User::whereNotNull('phone')
                ->whereRaw(
                    "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), 9) = ?",
                    [substr($digits, -9)],
                )
                ->with(['trainee', 'trainer', 'employee'])
                ->first();

            if ($user) {
                // After the response would be better, but the office hearing
                // about it is the entire point of the call — so a provider
                // failure is caught rather than swallowing the request.
                try {
                    $notifications->passwordResetRequested($user, $data['phone']);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        return $this->ok(message: 'تم إبلاغ إدارة المركز. سيتم التواصل معك على رقمك لتسليمك كلمة مرور جديدة.');
    }

    /**
     * An account that exists to use one of the apps, rather than to run the
     * center: it reaches its own file and nothing else.
     */
    protected function isAppAccount(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return false;
        }

        return ($user->trainee !== null || $user->trainer !== null)
            && ! $user->hasAnyPermission(...\App\Support\Permissions::sensitive());
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
