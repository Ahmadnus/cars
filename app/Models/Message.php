<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a conversation.
 *
 * Attachments are stored on the private disk and never served by the web
 * server: a voice note or a photo between a trainer and a trainee is personal,
 * so it is delivered only through an authenticated, policy-checked route.
 */
class Message extends Model
{
    use HasUuid;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_AUDIO = 'audio';

    public const TYPES = [self::TYPE_TEXT, self::TYPE_IMAGE, self::TYPE_AUDIO];

    protected $fillable = [
        'conversation_id', 'sender_id', 'sender_role', 'type', 'body',
        'attachment_path', 'attachment_mime', 'attachment_size',
        'duration_seconds', 'delivered_at', 'read_at',
    ];

    /**
     * The stored path is an internal detail and must never reach a client: it
     * would expose the disk layout and invite path guessing. The app receives a
     * download URL instead, built by the resource.
     */
    protected $hidden = ['attachment_path'];

    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'attachment_size' => 'integer',
            'duration_seconds' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function hasAttachment(): bool
    {
        return $this->attachment_path !== null;
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    /**
     * A one-line summary for the thread list and the push notification.
     *
     * An attachment is described rather than quoted — there is no text to show,
     * and a filename would leak the disk layout.
     */
    public function preview(int $limit = 80): string
    {
        return match ($this->type) {
            self::TYPE_IMAGE => '📷 صورة',
            self::TYPE_AUDIO => '🎤 رسالة صوتية',
            default => \Illuminate\Support\Str::limit((string) $this->body, $limit),
        };
    }
}
