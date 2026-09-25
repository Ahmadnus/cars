<?php

namespace App\Services;

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Exceptions\BusinessRuleException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Trainee;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The only writer of conversations and messages.
 *
 * Two invariants live here rather than in a controller, so every caller gets
 * them: the unread counter belongs to the *other* side and is adjusted in the
 * same transaction as the insert, and an attachment is written to the private
 * disk before the row that points at it exists — a row pointing at a file that
 * failed to upload would render as a permanently broken message.
 */
class ChatService
{
    /** Attachment ceilings. Anything larger is a mistake or an abuse. */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public const MAX_AUDIO_BYTES = 10 * 1024 * 1024;

    public const MAX_AUDIO_SECONDS = 300;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    public const AUDIO_MIMES = [
        'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/ogg',
        'audio/webm', 'audio/wav', 'audio/x-m4a', 'audio/m4a',
    ];

    public function __construct(
        protected NotificationService $notifications,
        protected PushService $push,
    ) {
    }

    /**
     * The thread for a trainee, created on first use.
     *
     * A trainee with no trainer assigned has nobody to talk to; saying so is
     * clearer than opening an empty thread that can never receive a reply.
     */
    public function conversationFor(Trainee $trainee): Conversation
    {
        if (! $trainee->trainer_id) {
            throw BusinessRuleException::make(
                'لم يتم إسناد مدرب لك بعد. راجع إدارة المركز.',
                ['trainer_id' => ['لا يوجد مدرب مسند.']],
            );
        }

        return Conversation::firstOrCreate(
            ['trainee_id' => $trainee->id, 'trainer_id' => $trainee->trainer_id],
            ['branch_id' => $trainee->branch_id],
        );
    }

    /**
     * Append a message and tell the other side.
     *
     * @param  array{type?: string, body?: ?string, duration_seconds?: ?int}  $data
     */
    public function send(
        Conversation $conversation,
        User $sender,
        array $data,
        ?UploadedFile $attachment = null,
    ): Message {
        $role = $conversation->roleFor($sender);

        if ($role === null) {
            throw BusinessRuleException::make('لا تملك صلاحية المراسلة في هذه المحادثة.');
        }

        if ($conversation->is_closed) {
            throw BusinessRuleException::make('تم إيقاف هذه المحادثة من قبل إدارة المركز.');
        }

        $type = $data['type'] ?? Message::TYPE_TEXT;
        $body = isset($data['body']) ? trim((string) $data['body']) : null;

        if (! in_array($type, Message::TYPES, true)) {
            throw BusinessRuleException::make('نوع الرسالة غير مدعوم.');
        }

        if ($type === Message::TYPE_TEXT && ($body === null || $body === '')) {
            throw BusinessRuleException::make(
                'لا يمكن إرسال رسالة فارغة.',
                ['body' => ['نص الرسالة مطلوب.']],
            );
        }

        if ($type !== Message::TYPE_TEXT && ! $attachment) {
            throw BusinessRuleException::make(
                'لم يتم إرفاق ملف.',
                ['attachment' => ['الملف مطلوب لهذا النوع من الرسائل.']],
            );
        }

        // Store the file first: a row pointing at a file that never arrived
        // would render as a broken message forever.
        $stored = $attachment ? $this->storeAttachment($conversation, $type, $attachment) : null;

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $role, $type, $body, $stored, $data) {
                // Lock the thread so two simultaneous sends cannot both read the
                // same counter and each write it back plus one.
                $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

                $message = Message::create([
                    'conversation_id' => $locked->id,
                    'sender_id' => $sender->id,
                    'sender_role' => $role,
                    'type' => $type,
                    'body' => $body,
                    'attachment_path' => $stored['path'] ?? null,
                    'attachment_mime' => $stored['mime'] ?? null,
                    'attachment_size' => $stored['size'] ?? null,
                    'duration_seconds' => $type === Message::TYPE_AUDIO
                        ? $this->audioDuration($data)
                        : null,
                ]);

                // The unread counter belongs to the recipient, never the sender.
                $recipientColumn = $role === 'trainee' ? 'trainer_unread' : 'trainee_unread';

                $locked->forceFill([
                    'last_message_at' => $message->created_at,
                    'last_message_preview' => $message->preview(),
                    $recipientColumn => $locked->{$recipientColumn} + 1,
                ])->save();

                return $message;
            });
        } catch (\Throwable $e) {
            // Do not leave an orphan file on the disk if the insert failed.
            if ($stored) {
                Storage::disk($this->disk())->delete($stored['path']);
            }

            throw $e;
        }

        $message->setRelation('conversation', $conversation->refresh());

        // Broadcasting and push are side effects: a dead provider must not undo
        // a message that is already stored.
        $this->announce($conversation, $message, $sender);

        return $message;
    }

    /**
     * Mark everything the other side sent as read, and zero this side's badge.
     *
     * Returns the number of messages that changed, so a caller can skip the
     * broadcast when nothing did.
     */
    public function markRead(Conversation $conversation, User $reader): int
    {
        $role = $conversation->roleFor($reader);

        if ($role === null) {
            return 0;
        }

        $changed = DB::transaction(function () use ($conversation, $role) {
            $locked = Conversation::whereKey($conversation->id)->lockForUpdate()->firstOrFail();

            $changed = Message::where('conversation_id', $locked->id)
                ->where('sender_role', '!=', $role)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $column = $role === 'trainee' ? 'trainee_unread' : 'trainer_unread';
            $locked->forceFill([$column => 0])->save();

            return $changed;
        });

        if ($changed > 0) {
            try {
                MessageRead::dispatch($conversation->fresh(), $role);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $changed;
    }

    /** A signed-in user's threads, most recent first. */
    public function threadsFor(User $user)
    {
        return Conversation::query()
            ->forUser($user)
            ->with(['trainee:id,uuid,full_name,phone', 'trainer:id,uuid,full_name,phone'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    /**
     * Validate and store an attachment on the private disk.
     *
     * The MIME type is taken from the file itself, never from the client-sent
     * name or content-type: a `.jpg` extension on a PHP file is the oldest
     * upload attack there is.
     *
     * @return array{path: string, mime: string, size: int}
     */
    protected function storeAttachment(Conversation $conversation, string $type, UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType();
        $size = (int) $file->getSize();

        [$allowed, $maxBytes, $label] = match ($type) {
            Message::TYPE_IMAGE => [self::IMAGE_MIMES, self::MAX_IMAGE_BYTES, 'صورة'],
            Message::TYPE_AUDIO => [self::AUDIO_MIMES, self::MAX_AUDIO_BYTES, 'رسالة صوتية'],
            default => throw BusinessRuleException::make('لا يمكن إرفاق ملف برسالة نصية.'),
        };

        if (! in_array($mime, $allowed, true)) {
            throw BusinessRuleException::make(
                'نوع الملف غير مدعوم لـ'.$label.'.',
                ['attachment' => ['النوع المرسل: '.$mime]],
            );
        }

        if ($size > $maxBytes) {
            throw BusinessRuleException::make(
                'حجم الملف أكبر من المسموح ('.round($maxBytes / 1048576).' ميجابايت).',
                ['attachment' => ['الحجم المرسل: '.round($size / 1048576, 2).' ميجابايت']],
            );
        }

        // A generated name, never the client's: the original could contain path
        // separators or a second extension.
        $path = $file->storeAs(
            'chat/'.$conversation->uuid,
            \Illuminate\Support\Str::uuid().'.'.$this->extensionFor($mime),
            $this->disk(),
        );

        if (! $path) {
            throw BusinessRuleException::make('تعذّر حفظ الملف. حاول مرة أخرى.');
        }

        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    protected function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a', 'audio/m4a', 'audio/aac' => 'm4a',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'weba',
            'audio/wav' => 'wav',
            default => 'bin',
        };
    }

    /** @param array<string, mixed> $data */
    protected function audioDuration(array $data): ?int
    {
        $seconds = isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : null;

        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        return min($seconds, self::MAX_AUDIO_SECONDS);
    }

    /** Tell the other side, in the app and on their phone. */
    protected function announce(Conversation $conversation, Message $message, User $sender): void
    {
        try {
            MessageSent::dispatch($message);
        } catch (\Throwable $e) {
            report($e);
        }

        $recipient = $this->recipientUser($conversation, $message->sender_role);

        if (! $recipient || $recipient->id === $sender->id) {
            return;
        }

        try {
            $this->push->send(
                $recipient,
                $this->senderName($conversation, $message->sender_role),
                $message->preview(120),
                [
                    'kind' => 'chat',
                    'conversation_id' => $conversation->uuid,
                    'message_id' => $message->uuid,
                ],
            );
        } catch (\Throwable $e) {
            report($e);
        }
    }

    protected function recipientUser(Conversation $conversation, string $senderRole): ?User
    {
        return $senderRole === 'trainee'
            ? $conversation->trainer?->user
            : $conversation->trainee?->user;
    }

    protected function senderName(Conversation $conversation, string $senderRole): string
    {
        return $senderRole === 'trainee'
            ? ($conversation->trainee?->full_name ?? 'المتدرب')
            : ($conversation->trainer?->full_name ?? 'المدرب');
    }

    protected function disk(): string
    {
        return config('filesystems.private_disk', 'private');
    }
}
