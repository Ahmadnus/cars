<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS delivery through Twilio's REST API.
 *
 * Enabled only when the admin has switched SMS on *and* credentials are
 * present, so a half-configured install silently falls back to the log
 * gateway rather than throwing on every notification.
 */
class TwilioSmsGateway implements ChannelGateway
{
    public function __construct(
        protected bool $enabled,
        protected ?string $accountSid,
        protected ?string $authToken,
        protected ?string $from,
        protected int $timeout = 10,
    ) {
    }

    public function name(): string
    {
        return 'sms';
    }

    public function isEnabled(): bool
    {
        return $this->enabled
            && filled($this->accountSid)
            && filled($this->authToken)
            && filled($this->from);
    }

    public function send(User $recipient, string $title, string $body, array $payload = []): bool
    {
        $to = $this->e164($recipient->phone);

        if (! $to) {
            return false;
        }

        $response = Http::asForm()
            ->timeout($this->timeout)
            ->withBasicAuth($this->accountSid, $this->authToken)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json", [
                'From' => $this->from,
                'To' => $to,
                'Body' => $body,
            ]);

        if ($response->failed()) {
            Log::warning('[sms] رفض مزوّد الرسائل الإرسال.', [
                'user_id' => $recipient->id,
                'status' => $response->status(),
                'error' => $response->json('message'),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Providers want E.164. Numbers are stored in local form, so a local
     * number is promoted using the configured country code.
     */
    protected function e164(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        $country = ltrim((string) config('services.messaging.country_code', '962'), '+');

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = $country.substr($digits, 1);
        } elseif (! str_starts_with($digits, $country)) {
            $digits = $country.$digits;
        }

        return '+'.$digits;
    }
}
