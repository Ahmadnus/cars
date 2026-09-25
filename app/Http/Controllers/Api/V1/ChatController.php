<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Trainer-trainee messaging.
 *
 * Authorization is membership, not a permission: the two people in a thread are
 * the only ones who may read or write it, and `Conversation::roleFor()` is the
 * single place that decides. A staff account — even an administrator — is not a
 * participant and gets 403 here; oversight goes through the dashboard, where it
 * is gated by `chat.monitor` and leaves an audit trail.
 */
class ChatController extends ApiController
{
    public function __construct(protected ChatService $chat)
    {
    }

    /** Threads for the signed-in user: one for a trainee, many for a trainer. */
    public function index(Request $request): JsonResponse
    {
        $threads = $this->chat->threadsFor($request->user())->get();

        return $this->ok(ConversationResource::collection($threads), meta: [
            'unread_total' => $threads->sum(fn (Conversation $c) => $c->unreadFor($request->user())),
        ]);
    }

    /**
     * The trainee's own thread, created on first open.
     *
     * Only a trainee can call this: a trainer has many threads and picks one
     * from the list instead.
     */
    public function mine(Request $request): JsonResponse
    {
        $trainee = $request->user()->trainee;

        if (! $trainee) {
            return $this->failed('هذا الحساب غير مرتبط بملف متدرب.', status: 403);
        }

        $conversation = $this->chat->conversationFor($trainee);

        return $this->ok(new ConversationResource($conversation->load('trainer', 'trainee')));
    }

    /**
     * A page of messages, newest first.
     *
     * `before` pages backwards through history by id rather than by page
     * number: new messages keep arriving at the top, and an offset would make
     * the reader skip or repeat a message mid-scroll.
     */
    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $this->assertParticipant($request, $conversation);

        $request->validate([
            'before' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) $request->input('limit', 30);

        $query = $conversation->messages()->with('sender:id,name')->orderByDesc('id');

        if ($before = $request->input('before')) {
            $cursor = Message::where('uuid', $before)->value('id');

            if ($cursor) {
                $query->where('id', '<', $cursor);
            }
        }

        $messages = $query->limit($limit + 1)->get();

        $hasMore = $messages->count() > $limit;
        $page = $messages->take($limit);

        return $this->ok(MessageResource::collection($page->values()), meta: [
            'has_more' => $hasMore,
            'next_before' => $hasMore ? $page->last()?->uuid : null,
            'unread' => $conversation->unreadFor($request->user()),
        ]);
    }

    /** Send a message, with an optional image or voice note. */
    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $this->assertParticipant($request, $conversation);

        $data = $request->validate([
            'type' => ['nullable', 'in:text,image,audio'],
            'body' => ['nullable', 'string', 'max:2000'],
            'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:'.ChatService::MAX_AUDIO_SECONDS],
            // The real type and size checks happen in the service against the
            // file's own contents; this only keeps absurd uploads out of PHP.
            'attachment' => ['nullable', 'file', 'max:10240'],
        ], [], [
            'body' => 'نص الرسالة',
            'attachment' => 'الملف',
        ]);

        $message = $this->chat->send(
            $conversation,
            $request->user(),
            $data,
            $request->file('attachment'),
        );

        return $this->created(
            new MessageResource($message->load('sender:id,name')),
            'تم إرسال الرسالة.',
        );
    }

    /** Mark the other side's messages as read and clear this side's badge. */
    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->assertParticipant($request, $conversation);

        $changed = $this->chat->markRead($conversation, $request->user());

        return $this->ok(['updated' => $changed], 'تم تحديث حالة القراءة.');
    }

    /**
     * Stream an attachment.
     *
     * Served from the private disk through this route so membership is checked
     * on every single fetch — a URL that leaked would still be useless to
     * anyone outside the conversation.
     */
    public function attachment(Request $request, Message $message): StreamedResponse|JsonResponse
    {
        $conversation = $message->conversation;

        $this->assertParticipant($request, $conversation);

        if (! $message->hasAttachment()) {
            return $this->failed('لا يوجد ملف مرفق بهذه الرسالة.', status: 404);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.private_disk', 'private'));

        if (! $disk->exists($message->attachment_path)) {
            return $this->failed('الملف غير موجود.', status: 404);
        }

        return $disk->response(
            $message->attachment_path,
            null,
            [
                'Content-Type' => $message->attachment_mime ?? 'application/octet-stream',
                // Never inline: an attacker-supplied file rendered in place is
                // how a chat attachment becomes a cross-site scripting hole.
                'Content-Disposition' => 'attachment',
                'Cache-Control' => 'private, max-age=3600',
            ],
        );
    }

    /** 403 unless the caller is one of the two people in the thread. */
    protected function assertParticipant(Request $request, Conversation $conversation): void
    {
        abort_unless(
            $conversation->includes($request->user()),
            403,
            'لا تملك صلاحية الوصول إلى هذه المحادثة.',
        );
    }
}
