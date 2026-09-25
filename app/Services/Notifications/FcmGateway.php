<?php

namespace App\Services\Notifications;

use App\Models\DeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, over the HTTP v1 API.
 *
 * v1 needs an OAuth token signed with the service account key rather than the
 * legacy server key, so this class mints one and caches it for slightly less
 * than its lifetime. The key never appears in the repository: it is a JSON file
 * whose path comes from the environment.
 *
 * Tokens the provider reports as dead are deleted here. Without that, an
 * uninstalled app keeps its row forever and every future send wastes a request
 * on it.
 */
class FcmGateway implements ChannelGateway
{
    protected const TOKEN_CACHE_KEY = 'fcm.access_token';

    protected const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(
        protected ?string $credentialsPath = null,
        protected ?string $projectId = null,
        protected bool $enabled = false,
    ) {
    }

    public function name(): string
    {
        return 'push';
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->projectId !== null
            && $this->credentialsPath !== null
            && is_readable($this->credentialsPath);
    }

    /**
     * Send to every device the user has registered.
     *
     * Returns true when at least one device accepted it: a user with an old
     * phone still in the table and a current one should not see the send
     * reported as a failure.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(User $recipient, string $title, string $body, array $payload = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $devices = DeviceToken::where('user_id', $recipient->id)->get();

        if ($devices->isEmpty()) {
            return false;
        }

        $accessToken = $this->accessToken();

        if (! $accessToken) {
            return false;
        }

        $delivered = false;

        foreach ($devices as $device) {
            if ($this->sendToDevice($accessToken, $device, $title, $body, $payload)) {
                $delivered = true;
            }
        }

        return $delivered;
    }

    /** @param array<string, mixed> $payload */
    protected function sendToDevice(
        string $accessToken,
        DeviceToken $device,
        string $title,
        string $body,
        array $payload,
    ): bool {
        $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->post($url, [
                    'message' => [
                        'token' => $device->token,
                        'notification' => ['title' => $title, 'body' => $body],
                        // Data values must be strings in FCM v1; casting here
                        // keeps every caller from having to remember.
                        'data' => array_map(
                            static fn ($value) => is_scalar($value) ? (string) $value : json_encode($value),
                            $payload,
                        ),
                        'android' => [
                            'priority' => 'high',
                            'notification' => ['sound' => 'default', 'channel_id' => 'default'],
                        ],
                        'apns' => [
                            'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        if ($response->successful()) {
            $device->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        // 404 UNREGISTERED and 400 INVALID_ARGUMENT on the token both mean this
        // device will never receive anything again.
        $status = (int) $response->status();
        $error = (string) $response->json('error.status', '');

        if ($status === 404 || $error === 'UNREGISTERED' || $error === 'NOT_FOUND') {
            $device->delete();

            return false;
        }

        Log::warning('[fcm] رفض المزوّد الإرسال.', [
            'status' => $status,
            'error' => $error,
            'user_id' => $device->user_id,
        ]);

        return false;
    }

    /**
     * A short-lived OAuth token for the service account.
     *
     * Cached just under the hour Google issues it for, so a burst of messages
     * costs one token exchange rather than one per message.
     */
    protected function accessToken(): ?string
    {
        return Cache::remember(self::TOKEN_CACHE_KEY, now()->addMinutes(55), function () {
            $credentials = $this->credentials();

            if (! $credentials) {
                return null;
            }

            $now = time();

            $jwt = $this->signJwt([
                'iss' => $credentials['client_email'],
                'scope' => self::SCOPE,
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $credentials['private_key']);

            if (! $jwt) {
                return null;
            }

            try {
                $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]);
            } catch (\Throwable $e) {
                report($e);

                return null;
            }

            if (! $response->successful()) {
                Log::error('[fcm] تعذّر الحصول على رمز الوصول.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** @return array{client_email: string, private_key: string}|null */
    protected function credentials(): ?array
    {
        if (! $this->credentialsPath || ! is_readable($this->credentialsPath)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (! is_array($json) || ! isset($json['client_email'], $json['private_key'])) {
            Log::error('[fcm] ملف بيانات الخدمة غير صالح.');

            return null;
        }

        return [
            'client_email' => (string) $json['client_email'],
            'private_key' => (string) $json['private_key'],
        ];
    }

    /** @param array<string, mixed> $claims */
    protected function signJwt(array $claims, string $privateKey): ?string
    {
        $encode = static fn (array $data): string => rtrim(
            strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES)), '+/', '-_'),
            '=',
        );

        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        $signature = '';

        if (! openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            Log::error('[fcm] تعذّر توقيع الرمز — تحقّق من المفتاح الخاص.');

            return null;
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
