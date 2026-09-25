<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Message
 */
class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'body' => $this->body,
            'sender_role' => $this->sender_role,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender->name),

            // The stored path never leaves the server. An attachment is reached
            // through this route, which re-checks that the caller is one of the
            // two participants on every request.
            'attachment_url' => $this->hasAttachment()
                ? route('api.v1.chat.attachment', ['message' => $this->uuid])
                : null,
            'attachment_mime' => $this->attachment_mime,
            'attachment_size' => $this->attachment_size,
            'duration_seconds' => $this->duration_seconds,

            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
