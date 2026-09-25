<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone registered for push notifications. */
class DeviceToken extends Model
{
    protected $fillable = [
        'user_id', 'token', 'token_hash', 'platform', 'app', 'last_used_at',
    ];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Register a token to a user.
     *
     * Keyed on the token, not the user: when a phone changes hands the new
     * owner must take the token over, or the previous account would keep
     * receiving that device's notifications.
     */
    public static function register(User $user, string $token, string $platform, string $app = 'trainee'): self
    {
        return static::updateOrCreate(
            ['token_hash' => hash('sha256', $token)],
            [
                'user_id' => $user->id,
                'token' => $token,
                'platform' => $platform,
                'app' => $app,
                'last_used_at' => now(),
            ],
        );
    }
}
