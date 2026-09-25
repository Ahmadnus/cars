<?php

namespace App\Events;

use App\Models\RegistrationRequest;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A join request arrived or was decided.
 *
 * Broadcast to the staff queue only, so a new request appears in the dashboard
 * without a refresh.
 *
 * The applicant deliberately gets no channel. They have no account yet, so
 * there would be nothing to authenticate a subscription against — the only
 * thing they hold is the reference, and a channel guarded by a value that also
 * travels in URLs is a weaker door than the rest of the system. They learn the
 * decision two ways instead: the status endpoint, and a push notification once
 * approval creates their login. Approval takes minutes, not seconds, so nothing
 * is lost.
 */
class RegistrationRequestUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public RegistrationRequest $request,
        public string $action = 'created',
    ) {
    }

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PresenceChannel('staff.registrations')];
    }

    public function broadcastAs(): string
    {
        return 'registration.'.$this->action;
    }

    /**
     * Deliberately narrow.
     *
     * The staff queue needs enough to render a row, and the applicant needs the
     * decision — but the national id, address and notes stay out of a broadcast
     * payload: a socket frame is the wrong place for personal data that the
     * authenticated REST endpoint already serves.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'request' => [
                'id' => $this->request->uuid,
                'reference' => $this->request->reference,
                'full_name' => $this->request->full_name,
                'status' => $this->request->status,
                'status_label' => $this->request->statusLabel(),
                'branch' => $this->request->branch?->name,
                'created_at' => $this->request->created_at?->toIso8601String(),
                'decision_reason' => $this->request->decision_reason,
            ],
        ];
    }
}
