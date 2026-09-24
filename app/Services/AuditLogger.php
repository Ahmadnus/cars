<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes the audit trail.
 *
 * Every sensitive financial or administrative operation calls this from inside
 * the same database transaction as the change itself, so an audit entry can
 * never survive a rolled-back operation (or go missing after a committed one).
 */
class AuditLogger
{
    public function __construct(protected BranchContext $branchContext)
    {
    }

    public function log(
        string $action,
        ?Model $subject = null,
        ?array $before = null,
        ?array $after = null,
        ?string $description = null,
        ?string $reason = null,
        ?User $user = null,
        ?int $branchId = null,
    ): AuditLog {
        $user ??= auth()->user();
        $request = request();

        return AuditLog::create([
            'user_id' => $user?->id,
            'branch_id' => $branchId ?? $this->resolveBranchId($subject),
            'action' => $action,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'reason' => $reason,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
        ]);
    }

    /** Records a change by diffing the model's own dirty state. */
    public function logUpdate(string $action, Model $subject, array $originalAttributes, ?string $reason = null): AuditLog
    {
        $changed = array_keys($subject->getChanges());
        $changed = array_diff($changed, ['updated_at']);

        return $this->log(
            action: $action,
            subject: $subject,
            before: $this->only($originalAttributes, $changed),
            after: $this->only($subject->getAttributes(), $changed),
            reason: $reason,
        );
    }

    public function logCreate(string $action, Model $subject, ?string $description = null): AuditLog
    {
        return $this->log(
            action: $action,
            subject: $subject,
            after: $this->redact($subject->getAttributes()),
            description: $description,
        );
    }

    public function logDelete(string $action, Model $subject, ?string $reason = null): AuditLog
    {
        return $this->log(
            action: $action,
            subject: $subject,
            before: $this->redact($subject->getAttributes()),
            reason: $reason,
        );
    }

    protected function resolveBranchId(?Model $subject): ?int
    {
        if ($subject && isset($subject->branch_id)) {
            return (int) $subject->branch_id;
        }

        return $this->branchContext->currentId() ?? auth()->user()?->branch_id;
    }

    protected function only(array $attributes, array $keys): array
    {
        return $this->redact(array_intersect_key($attributes, array_flip($keys)));
    }

    /** Credentials and tokens never reach the audit trail. */
    protected function redact(array $attributes): array
    {
        foreach (['password', 'remember_token', 'two_factor_secret'] as $key) {
            unset($attributes[$key]);
        }

        return $attributes;
    }
}
