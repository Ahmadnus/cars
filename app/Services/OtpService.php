<?php

namespace App\Services;

use App\Models\OtpCode;
use App\Models\User;
use App\Support\Permissions;
use App\Services\Notifications\ChannelGateway;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Issues and checks the one-time passcodes the Trainee app logs in with.
 *
 * Two rules shape this class:
 *
 *  - The passcode is a credential, so it is stored hashed and burnt after a
 *    fixed number of wrong guesses. Asking for a new code invalidates the
 *    previous one, which stops an attacker from widening the guess space by
 *    requesting several at once.
 *  - Whether a phone belongs to an account is not disclosed. `request()`
 *    answers identically either way and simply sends nothing for an unknown
 *    number, so the endpoint cannot be used to enumerate customers.
 */
class OtpService
{
    /** @param array<int, ChannelGateway> $gateways */
    public function __construct(
        protected array $gateways = [],
    ) {
    }

    /**
     * Issue a passcode for a phone number.
     *
     * @return array{sent: bool, channel: ?string, expires_in: int, code: ?string}
     *         `code` is only ever populated when `otp.expose_code` is on.
     */
    public function request(string $phone, ?string $ip = null, string $purpose = 'login'): array
    {
        $phone = $this->normalisePhone($phone);
        $user = $this->userFor($phone);

        // Unknown number: answer as though a code went out, but issue nothing.
        if (! $user) {
            $ttl = (int) config('otp.ttl_minutes', 5);

            return ['sent' => false, 'channel' => null, 'expires_in' => $ttl * 60, 'code' => null];
        }

        return $this->issue($phone, $user, $purpose, $ip);
    }

    /**
     * Issue a passcode to a phone that has no account yet.
     *
     * Used by self-registration: an applicant proves they hold the number before
     * the join form is accepted, and at that point there is nothing to look up.
     * Unlike `request()` this does not pretend — the caller is not asking about
     * an account, so there is no existence to conceal.
     *
     * @return array{sent: bool, channel: ?string, expires_in: int, code: ?string}
     */
    public function requestForPhone(string $phone, ?string $ip = null, string $purpose = 'registration'): array
    {
        $phone = $this->normalisePhone($phone);

        return $this->issue($phone, $this->userFor($phone), $purpose, $ip);
    }

    /**
     * Mint, store and deliver a passcode.
     *
     * @return array{sent: bool, channel: ?string, expires_in: int, code: ?string}
     */
    protected function issue(string $phone, ?User $user, string $purpose, ?string $ip): array
    {
        $ttl = (int) config('otp.ttl_minutes', 5);

        $fixed = $this->fixedCodeFor($phone);
        $code = $fixed ?? $this->generateCode();

        // A fresh code retires every outstanding one for this phone.
        OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        // With no account yet the gateways still only need a number to send to,
        // so an unsaved carrier is enough to address the message.
        $addressee = $user ?? new User(['phone' => $phone]);

        $channel = $fixed !== null ? 'fixed' : $this->deliver($addressee, $code, $ttl);

        OtpCode::create([
            'phone' => $phone,
            'user_id' => $user?->id,
            'code_hash' => Hash::make($code),
            'purpose' => $purpose,
            'channel' => $channel,
            'ip_address' => $ip,
            'expires_at' => now()->addMinutes($ttl),
        ]);

        return [
            'sent' => true,
            'channel' => $channel,
            'expires_in' => $ttl * 60,
            'code' => config('otp.expose_code') ? $code : null,
        ];
    }

    /**
     * Check a passcode and, on success, consume it.
     *
     * @return array{ok: bool, user: ?User, error: ?string}
     */
    public function verify(string $phone, string $code, string $purpose = 'login'): array
    {
        $phone = $this->normalisePhone($phone);

        $record = OtpCode::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $record) {
            return $this->reject('لم يتم طلب رمز لهذا الرقم. اطلب رمزاً جديداً.');
        }

        if ($record->isExpired()) {
            return $this->reject('انتهت صلاحية الرمز. اطلب رمزاً جديداً.');
        }

        $maxAttempts = (int) config('otp.max_attempts', 5);

        if ($record->attempts >= $maxAttempts) {
            return $this->reject('تم تجاوز عدد المحاولات. اطلب رمزاً جديداً.');
        }

        if (! Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            $left = max(0, $maxAttempts - $record->attempts);

            return $this->reject(
                $left > 0
                    ? 'الرمز غير صحيح. المحاولات المتبقية: '.$left.'.'
                    : 'الرمز غير صحيح وتم تجاوز عدد المحاولات. اطلب رمزاً جديداً.',
            );
        }

        $user = $record->user;

        // Only signing in needs an account behind the code. A registration
        // passcode proves control of a phone that has no account by definition.
        if (! $user && $purpose === 'login') {
            return $this->reject('لا يوجد حساب مرتبط بهذا الرقم.');
        }

        $record->forceFill(['consumed_at' => now()])->save();

        return ['ok' => true, 'user' => $user, 'error' => null];
    }

    /** The account a passcode would log in, or null when the number is unknown. */
    public function userFor(string $phone): ?User
    {
        return User::query()
            ->where('phone', $this->normalisePhone($phone))
            ->where('status', 'active')
            ->first();
    }

    /**
     * Reduce a phone number to the local form the accounts are stored in, so
     * +962 79..., 00962 79... and 079... all resolve to the same account.
     */
    public function normalisePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        foreach (['00962', '962'] as $prefix) {
            if (str_starts_with($digits, $prefix)) {
                $digits = substr($digits, strlen($prefix));
                break;
            }
        }

        // Local subscriber numbers are written with a leading zero.
        if ($digits !== '' && ! str_starts_with($digits, '0')) {
            $digits = '0'.$digits;
        }

        return $digits;
    }

    /**
     * The fixed demo passcode for this number, when one is configured.
     *
     * Refused for any account holding a sensitive permission. A passcode that
     * never changes is a standing credential, and a standing credential must not
     * reach money, salaries, personal documents or private conversations — so a
     * demo trainee or trainer may use one, and an administrator may not, however
     * the environment is configured.
     */
    public function fixedCodeFor(string $phone): ?string
    {
        if (! config('otp.enable_fixed_codes')) {
            return null;
        }

        $phone = $this->normalisePhone($phone);
        $code = ((array) config('otp.fixed_codes', []))[$phone] ?? null;

        if ($code === null) {
            return null;
        }

        $user = $this->userFor($phone);

        if ($user && $this->isPrivileged($user)) {
            Log::warning('[otp] رُفض رمز ثابت لحساب يملك صلاحيات حسّاسة.', [
                'user_id' => $user->id,
            ]);

            return null;
        }

        return $code;
    }

    /** Whether this account can reach anything the catalogue marks sensitive. */
    protected function isPrivileged(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        foreach (Permissions::sensitive() as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** Seconds the caller must still wait before another code may be issued. */
    public function cooldownFor(string $phone, string $purpose = 'login'): int
    {
        $last = OtpCode::query()
            ->where('phone', $this->normalisePhone($phone))
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();

        if (! $last) {
            return 0;
        }

        $ready = $last->created_at->addSeconds((int) config('otp.resend_after_seconds', 60));

        return max(0, (int) now()->diffInSeconds($ready, false));
    }

    /** Codes issued to this phone within the last hour. */
    public function issuedLastHour(string $phone, string $purpose = 'login'): int
    {
        return OtpCode::query()
            ->where('phone', $this->normalisePhone($phone))
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subHour())
            ->count();
    }

    /**
     * Hand the code to the first enabled channel. Returns the channel that
     * accepted it, or null when none did — the caller still stores the code,
     * because in development the log gateway is the delivery mechanism.
     */
    protected function deliver(User $user, string $code, int $ttlMinutes): ?string
    {
        $body = str_replace(
            [':code', ':minutes'],
            [$code, (string) $ttlMinutes],
            (string) config('otp.message'),
        );

        foreach ((array) config('otp.channels', []) as $name) {
            $gateway = collect($this->gateways)->first(
                fn (ChannelGateway $g) => $g->name() === $name && $g->isEnabled(),
            );

            if (! $gateway) {
                continue;
            }

            try {
                if ($gateway->send($user, 'رمز الدخول', $body, ['type' => 'otp'])) {
                    return $name;
                }
            } catch (\Throwable $e) {
                // A dead provider must not block the login flow; fall through
                // to the next channel and, failing that, to the log.
                report($e);
            }
        }

        Log::info('[otp] لم تُفعّل أي قناة إرسال — الرمز متاح في السجل فقط.', [
            'user_id' => $user->id,
            'code' => $code,
        ]);

        return null;
    }

    protected function generateCode(): string
    {
        $length = max(4, (int) config('otp.length', 6));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }

    /** @return array{ok: bool, user: ?User, error: string} */
    protected function reject(string $error): array
    {
        return ['ok' => false, 'user' => null, 'error' => $error];
    }
}
