<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Conversation
 */
class ConversationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $role = $viewer ? $this->roleFor($viewer) : null;

        // Each side is shown the *other* party, because that is whose name and
        // avatar belong at the top of the thread.
        $other = $role === 'trainee' ? $this->trainer : $this->trainee;

        return [
            'id' => $this->uuid,
            'channel' => $this->channelName(),
            'my_role' => $role,
            'other_party' => $other ? [
                'name' => $other->full_name,
                'phone' => $other->phone,
            ] : null,
            'unread' => $viewer ? $this->unreadFor($viewer) : 0,
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'is_closed' => $this->is_closed,
        ];
    }
}
