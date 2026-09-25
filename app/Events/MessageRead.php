<?php

namespace App\Events;

use App\Models\Conversation;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** One side opened the thread, so the other side's ticks turn blue. */
class MessageRead implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Conversation $conversation,
        public string $readerRole,
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->conversation->channelName())];
    }

    public function broadcastAs(): string
    {
        return 'message.read';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->uuid,
            'reader_role' => $this->readerRole,
            'read_at' => now()->toIso8601String(),
        ];
    }
}
