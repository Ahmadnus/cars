<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Placeholder gateway used until a real provider is wired up.
 *
 * Writes the message to the log so the notification flow can be exercised and
 * tested end to end without an external account.
 */
class LogChannelGateway implements ChannelGateway
{
    public function __construct(protected string $channel, protected bool $enabled = false)
    {
    }

    public function name(): string
    {
        return $this->channel;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function send(User $recipient, string $title, string $body, array $payload = []): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        Log::channel(config('logging.default'))->info("[notify:{$this->channel}] {$title}", [
            'user_id' => $recipient->id,
            'phone' => $recipient->phone,
            'body' => $body,
            'payload' => $payload,
        ]);

        return true;
    }
}
