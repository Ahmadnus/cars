<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The thread between one trainee and their trainer.
 *
 * There is exactly one per pair, created on demand. Unread counters are stored
 * rather than counted: the badge is read on every poll, and counting unread
 * rows each time would scan the whole message table.
 */
class Conversation extends Model
{
    use HasUuid;

    /**
     * Counter defaults.
     *
     * The columns default to 0 in the database, but a model built by
     * `firstOrCreate` never read them back — so the accessors below saw null
     * and the resource crashed on the very first open of a thread.
     */
    protected $attributes = [
        'trainee_unread' => 0,
        'trainer_unread' => 0,
        'is_closed' => false,
    ];

    protected $fillable = [
        'branch_id', 'trainee_id', 'trainer_id', 'last_message_at',
        'last_message_preview', 'trainee_unread', 'trainer_unread', 'is_closed',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'is_closed' => 'boolean',
            'trainee_unread' => 'integer',
            'trainer_unread' => 'integer',
        ];
    }

    public function trainee(): BelongsTo
    {
        return $this->belongsTo(Trainee::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** Threads either side of this user can see. */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query
            ->when(
                $user->trainee,
                fn (Builder $q) => $q->where('conversations.trainee_id', $user->trainee->id),
            )
            ->when(
                $user->trainer,
                fn (Builder $q) => $q->where('conversations.trainer_id', $user->trainer->id),
            );
    }

    /**
     * Which side of the thread this user sits on, or null when they are neither.
     *
     * Returning null is what every authorization check keys off, so a staff
     * account with no trainee or trainer file is not silently treated as a
     * participant.
     */
    public function roleFor(User $user): ?string
    {
        if ($user->trainee && $user->trainee->id === $this->trainee_id) {
            return 'trainee';
        }

        if ($user->trainer && $user->trainer->id === $this->trainer_id) {
            return 'trainer';
        }

        return null;
    }

    public function includes(User $user): bool
    {
        return $this->roleFor($user) !== null;
    }

    public function unreadFor(User $user): int
    {
        return (int) match ($this->roleFor($user)) {
            'trainee' => $this->trainee_unread,
            'trainer' => $this->trainer_unread,
            default => 0,
        };
    }

    /** The private broadcast channel both participants subscribe to. */
    public function channelName(): string
    {
        return 'conversation.'.$this->uuid;
    }
}
