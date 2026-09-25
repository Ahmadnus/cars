<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single issued passcode. See the migration for why the code is hashed.
 */
class OtpCode extends Model
{
    protected $fillable = [
        'phone', 'user_id', 'code_hash', 'purpose', 'attempts',
        'channel', 'ip_address', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Still usable: not spent, not expired, attempts left. */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('attempts', '<', config('otp.max_attempts', 5));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
