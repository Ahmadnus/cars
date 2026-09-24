<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared policy behaviour: a permission check plus branch ownership.
 *
 * Holding `trainees.view` is not enough to open a trainee from another branch —
 * both must pass. Subclasses declare their permission prefix and override only
 * where a module differs.
 */
abstract class BasePolicy
{
    /** Permission namespace, e.g. "trainees". */
    protected string $prefix = '';

    public function viewAny(User $user): bool
    {
        return $user->hasPermission($this->permission('view'));
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permission('view')) && $this->sameBranch($user, $model);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission($this->permission('create'));
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permission('update')) && $this->sameBranch($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission($this->permission('delete')) && $this->sameBranch($user, $model);
    }

    public function restore(User $user, Model $model): bool
    {
        return $this->delete($user, $model);
    }

    /** Hard delete is never offered through the UI for business records. */
    public function forceDelete(User $user, Model $model): bool
    {
        return false;
    }

    protected function permission(string $action): string
    {
        return "{$this->prefix}.{$action}";
    }

    /** A record with no branch (a shared catalogue row) is visible to everyone. */
    protected function sameBranch(User $user, Model $model): bool
    {
        $branchId = $model->branch_id ?? null;

        return $branchId === null || $user->canAccessBranch((int) $branchId);
    }
}
