<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\UserResource;
use App\Models\LoginHistory;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phone + passcode login, which is how trainees sign in.
 *
 * Staff keep the password flow in AuthController; trainees have no password at
 * all, so this is their only door. Both flows mint the same Sanctum tokens and
 * write the same login history, so nothing downstream has to care which was
 * used.
 */
class OtpController extends ApiController
{
    public function __construct(protected OtpService $otp)
    {
    }

    /** Send a passcode to a phone number. */
    public function request(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
        ], [], ['phone' => 'رقم الهاتف']);

        $phone = $this->otp->normalisePhone($data['phone']);

        if ($phone === '') {
            return $this->failed('رقم الهاتف غير صحيح.', ['phone' => ['رقم الهاتف غير صحيح.']]);
        }

        // Two separate brakes: a short gap between codes, and a ceiling per
        // hour so a number cannot be used to burn provider credit.
        if (($wait = $this->otp->cooldownFor($phone)) > 0) {
            return $this->failed(
                'يرجى الانتظار '.$wait.' ثانية قبل طلب رمز جديد.',
                ['phone' => ['يرجى الانتظار قبل طلب رمز جديد.']],
                429,
            );
        }

        if ($this->otp->issuedLastHour($phone) >= (int) config('otp.hourly_limit', 5)) {
            return $this->failed('تم طلب عدد كبير من الرموز. حاول بعد ساعة.', status: 429);
        }

        $result = $this->otp->request($phone, $request->ip());

        return $this->ok([
            // Deliberately the same shape for a known and an unknown number:
            // the response must not reveal whether an account exists.
            'expires_in' => $result['expires_in'],
            'resend_after' => (int) config('otp.resend_after_seconds', 60),
            'code_length' => (int) config('otp.length', 6),
            // Development aid only — null unless `otp.expose_code` is on.
            'debug_code' => $result['code'],
        ], 'إذا كان الرقم مسجلاً لدينا فسيصلك رمز التحقق خلال لحظات.');
    }

    /** Exchange a passcode for an API token. */
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code' => ['required', 'string', 'max:10'],
            'device_name' => ['required', 'string', 'max:100'],
        ], [], [
            'phone' => 'رقم الهاتف',
            'code' => 'رمز التحقق',
            'device_name' => 'اسم الجهاز',
        ]);

        $result = $this->otp->verify($data['phone'], $data['code']);

        if (! $result['ok']) {
            $this->record(null, $request, 'failed', $data['phone']);

            return $this->failed($result['error'], ['code' => [$result['error']]], 401);
        }

        $user = $result['user'];

        if ($user->isLocked()) {
            $this->record($user, $request, 'locked');

            return $this->failed('تم إيقاف الحساب مؤقتاً.', status: 423);
        }

        if (! $user->isActive()) {
            $this->record($user, $request, 'failed');

            return $this->failed('هذا الحساب غير مفعّل.', status: 403);
        }

        // One token per device name, matching the password flow.
        $user->tokens()->where('name', $data['device_name'])->delete();
        $token = $user->createToken(
            $data['device_name'],
            $user->isSuperAdmin() ? ['*'] : $user->permissionNames()->all(),
        );

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

    protected function record(?User $user, Request $request, string $result, ?string $identifier = null): void
    {
        LoginHistory::create([
            'user_id' => $user?->id,
            'email' => $identifier ?? $user?->email,
            'result' => $result,
            'guard' => 'otp',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);
    }
}
