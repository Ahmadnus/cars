<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp delivery through Meta's Cloud API.
 *
 * Meta only allows free-form text inside a 24-hour window opened by the
 * customer; outside it a pre-approved template must be used. Both shapes are
 * supported — set `services.whatsapp.template` to send as a template.
 */
class WhatsAppCloudGateway implements ChannelGateway
{
    public function __construct(
        protected bool $enabled,
        protected ?string $phoneNumberId,
        protected ?string $accessToken,
        protected ?string $template = null,
        protected string $templateLanguage = 'ar',
        protected string $apiVersion = 'v21.0',
        protected int $timeout = 10,
    ) {
    }

    public function name(): string
    {
        return 'whatsapp';
    }

    public function isEnabled(): bool
    {
        return $this->enabled && filled($this->phoneNumberId) && filled($this->accessToken);
    }

    public function send(User $recipient, string $title, string $body, array $payload = []): bool
    {
        $to = $this->e164($recipient->phone);

        if (! $to) {
            return false;
        }

        $response = Http::withToken($this->accessToken)
            ->timeout($this->timeout)
            ->post(
                "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages",
                $this->message($to, $body),
            );

        if ($response->failed()) {
            Log::warning('[whatsapp] رفض مزوّد واتساب الإرسال.', [
                'user_id' => $recipient->id,
                'status' => $response->status(),
                'error' => $response->json('error.message'),
            ]);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    protected function message(string $to, string $body): array
    {
        $base = ['messaging_product' => 'whatsapp', 'to' => $to];

        if (! $this->template) {
            return $base + ['type' => 'text', 'text' => ['body' => $body]];
        }

        return $base + [
            'type' => 'template',
            'template' => [
                'name' => $this->template,
                'language' => ['code' => $this->templateLanguage],
                'components' => [[
                    'type' => 'body',
                    'parameters' => [['type' => 'text', 'text' => $body]],
                ]],
            ],
        ];
    }

    /** See TwilioSmsGateway::e164() — same local-to-international promotion. */
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

        // Meta expects the number without a plus.
        return $digits;
    }
}
