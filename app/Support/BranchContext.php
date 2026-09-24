<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The branch the current request is scoped to.
 *
 * Null means "every branch this user may access" — only users flagged for
 * all-branch access can actually be in that state without it narrowing anything.
 * The value is validated against the user's grants on every read, so a stale or
 * tampered session key can never widen access.
 */
class BranchContext
{
    public const SESSION_KEY = 'current_branch_id';

    protected ?int $resolved = null;

    protected bool $hasResolved = false;

    public function currentId(): ?int
    {
        if ($this->hasResolved) {
            return $this->resolved;
        }

        $this->hasResolved = true;
        $user = auth()->user();

        if (! $user) {
            return $this->resolved = null;
        }

        $candidate = session(self::SESSION_KEY);
        $allowed = $user->accessibleBranchIds();

        if ($candidate !== null && in_array((int) $candidate, $allowed, true)) {
            return $this->resolved = (int) $candidate;
        }

        // A user without all-branch access must always be pinned to one branch.
        if (! $user->canAccessAllBranches()) {
            return $this->resolved = $allowed[0] ?? null;
        }

        return $this->resolved = null;
    }

    public function current(): ?Branch
    {
        $id = $this->currentId();

        return $id ? Branch::find($id) : null;
    }

    /** Set the active branch, rejecting any branch the user cannot access. */
    public function set(?int $branchId, ?User $user = null): bool
    {
        $user ??= auth()->user();

        if (! $user) {
            return false;
        }

        if ($branchId === null) {
            if (! $user->canAccessAllBranches()) {
                return false;
            }

            session()->forget(self::SESSION_KEY);
        } else {
            if (! in_array($branchId, $user->accessibleBranchIds(), true)) {
                return false;
            }

            session([self::SESSION_KEY => $branchId]);
        }

        $this->hasResolved = false;
        $this->resolved = null;

        return true;
    }

    /** Branches the user may switch between, for the selector. */
    public function selectable(?User $user = null): Collection
    {
        $user ??= auth()->user();

        if (! $user) {
            return collect();
        }

        return Branch::query()
            ->whereIn('id', $user->accessibleBranchIds())
            ->orderBy('name')
            ->get();
    }

    /**
     * Branch ids a query should be limited to right now: the selected branch,
     * or every branch the user can reach.
     */
    public function scopeIds(?User $user = null): array
    {
        $user ??= auth()->user();

        if (! $user) {
            return [];
        }

        $current = $this->currentId();

        return $current !== null ? [$current] : $user->accessibleBranchIds();
    }

    /** Branch id to stamp on newly created records. */
    public function defaultForWrite(?User $user = null): ?int
    {
        $user ??= auth()->user();

        return $this->currentId() ?? $user?->branch_id;
    }

    public function forget(): void
    {
        $this->hasResolved = false;
        $this->resolved = null;
    }
}
