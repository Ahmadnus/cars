<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Generates human-readable sequential references (trainee numbers, receipt
 * numbers, expense references).
 *
 * The counter is derived under a row lock on the table's own max value inside
 * the caller's transaction, so two concurrent requests cannot mint the same
 * number. Every target column also carries a unique index as the last line of
 * defence.
 */
class NumberGenerator
{
    /**
     * @param  string  $table   table holding the number
     * @param  string  $column  column holding the number
     * @param  string  $prefix  literal prefix, e.g. "TR"
     * @param  bool    $withYear  insert the current year between prefix and counter
     * @param  int     $padding  digits in the counter
     */
    public function next(
        string $table,
        string $column,
        string $prefix,
        bool $withYear = true,
        int $padding = 5,
    ): string {
        $stem = $withYear ? $prefix.'-'.now()->format('Y').'-' : $prefix.'-';

        // lockForUpdate on the matching rows keeps a second transaction waiting
        // until this one commits its new number.
        $last = DB::table($table)
            ->where($column, 'like', $stem.'%')
            ->lockForUpdate()
            ->max($column);

        $counter = $last ? ((int) substr((string) $last, strlen($stem))) + 1 : 1;

        return $stem.str_pad((string) $counter, $padding, '0', STR_PAD_LEFT);
    }

    public function traineeNumber(): string
    {
        return $this->next('trainees', 'trainee_number', 'MT');
    }

    public function trainerNumber(): string
    {
        return $this->next('trainers', 'trainer_number', 'MD');
    }

    public function employeeNumber(): string
    {
        return $this->next('employees', 'employee_number', 'MW');
    }

    public function paymentReceipt(): string
    {
        return $this->next('payments', 'receipt_number', 'RC');
    }

    public function payrollReceipt(): string
    {
        return $this->next('payroll_payments', 'receipt_number', 'SL');
    }

    public function trainerPayoutReceipt(): string
    {
        return $this->next('trainer_compensation_payments', 'receipt_number', 'TP');
    }

    public function expenseReference(): string
    {
        return $this->next('expenses', 'reference', 'EX');
    }
}
