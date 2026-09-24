<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Write-once record of a sensitive operation. Nothing in the application
 * updates or deletes these rows — AuditLogger is the only writer.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'branch_id', 'action', 'auditable_type', 'auditable_id',
        'description', 'reason', 'before', 'after', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Field-level diff between the before and after snapshots. */
    public function changedFields(): array
    {
        $before = $this->before ?? [];
        $after = $this->after ?? [];
        $changed = [];

        foreach (array_keys($before + $after) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;

            if ($old !== $new) {
                $changed[$key] = ['before' => $old, 'after' => $new];
            }
        }

        return $changed;
    }
}
