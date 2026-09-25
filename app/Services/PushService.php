<?php

namespace App\Services;

use App\Models\User;
use App\Services\Notifications\ChannelGateway;

/**
 * One entry point for "tell this person on their phone".
 *
 * Wrapping the gateway rather than calling it directly means the rest of the
 * app never checks whether push is configured: before credentials arrive the
 * log gateway is bound and every call is a no-op that still records what would
 * have been sent, so the flow can be built and tested first.
 */
class PushService
{
    public function __construct(protected ?ChannelGateway $gateway = null)
    {
    }

    public function isEnabled(): bool
    {
        return $this->gateway?->isEnabled() ?? false;
    }

    /**
     * @param  array<string, mixed>  $payload  Sent as FCM data, so the app can
     *                                         open the right screen on tap.
     * @return bool whether a device accepted it
     */
    public function send(User $recipient, string $title, string $body, array $payload = []): bool
    {
        if (! $this->gateway) {
            return false;
        }

        return $this->gateway->send($recipient, $title, $body, $payload);
    }
}
