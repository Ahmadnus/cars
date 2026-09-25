<?php

namespace App\Events;

use App\Http\Resources\MessageResource;
use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A new message, pushed to both participants.
 *
 * The channel is private and named after the conversation's uuid, so a client
 * can only subscribe after the broadcast auth callback has confirmed they are
 * one of the two participants — the id alone grants nothing.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Message $message)
    {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->message->conversation->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // The same shape the REST endpoint returns, so the app has one parser
        // for a message whether it arrived over the socket or over HTTP.
        return [
            'message' => (new MessageResource($this->message))->resolve(),
            'conversation_id' => $this->message->conversation->uuid,
        ];
    }
}
