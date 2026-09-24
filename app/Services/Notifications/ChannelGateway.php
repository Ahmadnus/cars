<?php

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Outbound transport for a notification channel that is not part of Laravel's
 * built-in set (SMS, WhatsApp, push).
 *
 * The first version ships only in-app notifications; these seams exist so a
 * provider can be added later by binding an implementation in a service
 * provider, without touching NotificationService or any caller.
 */
interface ChannelGateway
{
    /** Channel key, e.g. "sms". */
    public function name(): string;

    /** Whether the channel is configured and switched on. */
    public function isEnabled(): bool;

    /**
     * Deliver a message.
     *
     * @param  array<string, mixed>  $payload
     * @return bool whether the provider accepted the message
     */
    public function send(User $recipient, string $title, string $body, array $payload = []): bool;
}
