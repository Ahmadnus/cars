<?php

namespace App\Policies;

use App\Models\Trainee;
use App\Models\User;

class TraineePolicy extends BasePolicy
{
    protected string $prefix = 'trainees';

    /** Trainers may only reach the trainees assigned to them. */
    public function view(User $user, $model): bool
    {
        if (! parent::view($user, $model)) {
            return false;
        }

        return $this->notForeignTrainer($user, $model);
    }

    public function viewFinancials(User $user, Trainee $trainee): bool
    {
        return $user->hasPermission('trainees.financial') && $this->sameBranch($user, $trainee);
    }

    public function manageDocuments(User $user, Trainee $trainee): bool
    {
        return $user->hasPermission('trainees.documents') && $this->sameBranch($user, $trainee);
    }

    protected function notForeignTrainer(User $user, Trainee $trainee): bool
    {
        $trainer = $user->trainer;

        if (! $trainer || $user->hasPermission('trainees.update')) {
            return true;
        }

        return (int) $trainee->trainer_id === (int) $trainer->id;
    }
}
