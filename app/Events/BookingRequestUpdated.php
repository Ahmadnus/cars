<?php

namespace App\Events;

use App\Models\BookingRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A booking request was raised or decided.
 *
 * The trainee watches their own channel; reception watches the queue. This is
 * what makes a booking feel immediate without either side polling.
 */
class BookingRequestUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public BookingRequest $request,
        public string $action = 'created',
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        $channels = [new PresenceChannel('staff.bookings')];

        // A trainee always has a user once they can use the app; guard anyway,
        // because a request may be raised on their behalf from the dashboard.
        if ($this->request->trainee?->user_id) {
            $channels[] = new PrivateChannel('user.'.$this->request->trainee->user_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'booking.'.$this->action;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'request' => [
                'id' => $this->request->uuid,
                'type' => $this->request->type,
                'status' => $this->request->status,
                'requested_date' => $this->request->requested_date?->toDateString(),
                'requested_start_time' => $this->request->requested_start_time,
                'trainee' => $this->request->trainee?->full_name,
                'admin_note' => $this->request->admin_note,
            ],
        ];
    }
}
