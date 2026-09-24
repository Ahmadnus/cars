<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch ownership plus the query scopes that enforce it.
 *
 * Every read path that can reach another branch's data must go through
 * scopeVisibleTo(); the UI branch selector only narrows what is already allowed.
 */
trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Restrict to the branches a user may see, then narrow to the branch they
     * currently have selected (if any).
     */
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user ??= auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $table = $this->getTable();

        if (! $user->canAccessAllBranches()) {
            $query->whereIn("{$table}.branch_id", $user->accessibleBranchIds());
        }

        $current = app(BranchContext::class)->currentId();

        if ($current !== null) {
            $query->where("{$table}.branch_id", $current);
        }

        return $query;
    }

    public function scopeInBranch(Builder $query, int|Branch|null $branch): Builder
    {
        if ($branch === null) {
            return $query;
        }

        return $query->where($this->getTable().'.branch_id', $branch instanceof Branch ? $branch->id : $branch);
    }
}
