<?php

namespace App\Policies;

use App\Models\TrainerCompensationRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class TrainerCompensationRecordPolicy extends BasePolicy
{
    protected string $prefix = 'trainer_compensation';

    public function create(User $user): bool
    {
        return $user->hasPermission('trainer_compensation.manage');
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('trainer_compensation.manage')
            && $this->sameBranch($user, $model)
            && $model->isDraft();
    }

    public function approve(User $user, TrainerCompensationRecord $record): bool
    {
        return $user->hasPermission('trainer_compensation.manage')
            && $this->sameBranch($user, $record)
            && $record->isDraft();
    }

    public function pay(User $user, TrainerCompensationRecord $record): bool
    {
        return $user->hasPermission('trainer_compensation.pay')
            && $this->sameBranch($user, $record)
            && $record->isPayable();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
