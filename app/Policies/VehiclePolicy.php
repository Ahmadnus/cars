<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Vehicles use a single "manage" permission rather than separate CRUD verbs,
 * so every write maps onto it.
 */
class VehiclePolicy extends BasePolicy
{
    protected string $prefix = 'vehicles';

    public function create(User $user): bool
    {
        return $user->hasPermission('vehicles.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('vehicles.manage') && $this->sameBranch($user, $model);
    }

    public function delete(User $user, Model $model): bool
    {
        return $user->hasPermission('vehicles.manage') && $this->sameBranch($user, $model);
    }
}
