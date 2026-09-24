<?php

namespace App\Policies;

use App\Models\Payroll;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Seeing a payroll and paying one are deliberately separate permissions, so an
 * accountant can prepare salaries without also being able to disburse them.
 */
class PayrollPolicy extends BasePolicy
{
    protected string $prefix = 'payroll';

    public function update(User $user, Model $model): bool
    {
        return $user->hasPermission('payroll.create')
            && $this->sameBranch($user, $model)
            && ! $model->isLocked();
    }

    public function pay(User $user, Payroll $payroll): bool
    {
        return $user->hasPermission('payroll.pay')
            && $this->sameBranch($user, $payroll)
            && ! $payroll->isPaid()
            && ! $payroll->isCancelled();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
