<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Branch;
use App\Models\Cashbox;
use App\Models\CashboxClosing;
use App\Models\CashboxTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of the cash ledger.
 *
 * Every posting takes a row lock on the cashbox, reads the balance, appends an
 * immutable ledger line carrying the resulting balance, and writes the new
 * balance back — all inside the caller's transaction. That ordering is what
 * makes concurrent payments and payouts safe, and what lets the daily closing
 * reconcile against a balance that cannot have drifted.
 *
 * Callers MUST already be inside DB::transaction().
 */
class CashboxService
{
    public function __construct(protected AuditLogger $audit)
    {
    }

    /** The branch's active cashbox, created on first use. */
    public function forBranch(Branch|int $branch): Cashbox
    {
        $branchId = $branch instanceof Branch ? $branch->id : $branch;

        $cashbox = Cashbox::where('branch_id', $branchId)->where('status', 'active')->orderBy('id')->first();

        if ($cashbox) {
            return $cashbox;
        }

        return Cashbox::create([
            'branch_id' => $branchId,
            'name' => 'الصندوق الرئيسي',
            'opening_balance' => 0,
            'current_balance' => 0,
            'status' => 'active',
        ]);
    }

    public function deposit(
        Cashbox|int $cashbox,
        float $amount,
        string $category,
        ?Model $source = null,
        ?string $description = null,
        ?CarbonInterface $date = null,
    ): CashboxTransaction {
        return $this->post($cashbox, 'in', $amount, $category, $source, $description, $date);
    }

    public function withdraw(
        Cashbox|int $cashbox,
        float $amount,
        string $category,
        ?Model $source = null,
        ?string $description = null,
        ?CarbonInterface $date = null,
    ): CashboxTransaction {
        return $this->post($cashbox, 'out', $amount, $category, $source, $description, $date);
    }

    /**
     * Append one ledger line and move the balance.
     *
     * @throws BusinessRuleException on a non-positive amount
     */
    public function post(
        Cashbox|int $cashbox,
        string $direction,
        float $amount,
        string $category,
        ?Model $source = null,
        ?string $description = null,
        ?CarbonInterface $date = null,
        ?CashboxTransaction $reverses = null,
    ): CashboxTransaction {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw BusinessRuleException::make('لا يمكن تسجيل حركة صندوق بقيمة صفر أو أقل.');
        }

        // Lock first: the balance we read must still be current when we write.
        $id = $cashbox instanceof Cashbox ? $cashbox->id : $cashbox;
        $locked = Cashbox::whereKey($id)->lockForUpdate()->firstOrFail();

        $balanceAfter = round(
            $direction === 'in'
                ? (float) $locked->current_balance + $amount
                : (float) $locked->current_balance - $amount,
            2,
        );

        $transaction = CashboxTransaction::create([
            'cashbox_id' => $locked->id,
            'branch_id' => $locked->branch_id,
            'direction' => $direction,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'category' => $category,
            'sourceable_type' => $source ? $source::class : null,
            'sourceable_id' => $source?->getKey(),
            'description' => $description,
            'transaction_date' => ($date ?? now())->toDateString(),
            'reverses_id' => $reverses?->id,
            'created_by' => auth()->id(),
        ]);

        $locked->forceFill(['current_balance' => $balanceAfter])->save();

        return $transaction;
    }

    /**
     * Cancel a posting by writing its mirror image.
     *
     * The original row is never touched, so the ledger keeps both sides of the
     * correction.
     */
    public function reverse(CashboxTransaction $original, string $reason): CashboxTransaction
    {
        if ($original->reverses_id !== null) {
            throw BusinessRuleException::make('لا يمكن عكس حركة هي أصلاً حركة عكسية.');
        }

        $alreadyReversed = CashboxTransaction::where('reverses_id', $original->id)->exists();

        if ($alreadyReversed) {
            throw BusinessRuleException::make('تم عكس هذه الحركة مسبقاً.');
        }

        $reversal = $this->post(
            cashbox: $original->cashbox_id,
            direction: $original->direction === 'in' ? 'out' : 'in',
            amount: (float) $original->amount,
            category: $original->category,
            source: null,
            description: 'عكس حركة رقم '.$original->id.' — '.$reason,
            date: now(),
            reverses: $original,
        );

        $this->audit->log(
            action: 'cashbox.reversed',
            subject: $reversal,
            before: ['transaction_id' => $original->id, 'amount' => $original->amount],
            reason: $reason,
        );

        return $reversal;
    }

    /**
     * Reverse whatever a source document posted to the cashbox.
     *
     * Used when a payment is voided or an expense cancelled.
     */
    public function reverseFor(Model $source, string $reason): int
    {
        $transactions = CashboxTransaction::where('sourceable_type', $source::class)
            ->where('sourceable_id', $source->getKey())
            ->whereNull('reverses_id')
            ->get();

        $count = 0;

        foreach ($transactions as $transaction) {
            if (CashboxTransaction::where('reverses_id', $transaction->id)->exists()) {
                continue;
            }

            $this->reverse($transaction, $reason);
            $count++;
        }

        return $count;
    }

    /** Totals moved through a cashbox on one day. */
    public function dailyTotals(Cashbox $cashbox, CarbonInterface $date): array
    {
        $rows = CashboxTransaction::where('cashbox_id', $cashbox->id)
            ->whereDate('transaction_date', $date->toDateString())
            ->selectRaw('direction, SUM(amount) as total')
            ->groupBy('direction')
            ->pluck('total', 'direction');

        $in = round((float) ($rows['in'] ?? 0), 2);
        $out = round((float) ($rows['out'] ?? 0), 2);

        return ['in' => $in, 'out' => $out, 'net' => round($in - $out, 2)];
    }

    /**
     * Record the day's cash count.
     *
     * The expected balance is the ledger balance at the end of that day, so a
     * difference always means a real-world discrepancy rather than drift.
     */
    public function close(Cashbox $cashbox, CarbonInterface $date, float $countedBalance, ?string $notes = null): CashboxClosing
    {
        $day = $date->toDateString();

        $existing = CashboxClosing::where('cashbox_id', $cashbox->id)->where('closing_date', $day)->first();

        if ($existing) {
            throw BusinessRuleException::make('تم إقفال الصندوق لهذا اليوم مسبقاً.');
        }

        return DB::transaction(function () use ($cashbox, $day, $countedBalance, $notes) {
            $priorIn = (float) CashboxTransaction::where('cashbox_id', $cashbox->id)
                ->where('transaction_date', '<', $day)->where('direction', 'in')->sum('amount');
            $priorOut = (float) CashboxTransaction::where('cashbox_id', $cashbox->id)
                ->where('transaction_date', '<', $day)->where('direction', 'out')->sum('amount');

            $opening = round((float) $cashbox->opening_balance + $priorIn - $priorOut, 2);

            $dayIn = (float) CashboxTransaction::where('cashbox_id', $cashbox->id)
                ->where('transaction_date', $day)->where('direction', 'in')->sum('amount');
            $dayOut = (float) CashboxTransaction::where('cashbox_id', $cashbox->id)
                ->where('transaction_date', $day)->where('direction', 'out')->sum('amount');

            $expected = round($opening + $dayIn - $dayOut, 2);
            $counted = round($countedBalance, 2);

            $closing = CashboxClosing::create([
                'cashbox_id' => $cashbox->id,
                'branch_id' => $cashbox->branch_id,
                'closing_date' => $day,
                'opening_balance' => $opening,
                'total_in' => round($dayIn, 2),
                'total_out' => round($dayOut, 2),
                'expected_balance' => $expected,
                'counted_balance' => $counted,
                'difference' => round($counted - $expected, 2),
                'notes' => $notes,
                'closed_by' => auth()->id(),
            ]);

            $this->audit->log(
                action: 'cashbox.closed',
                subject: $closing,
                after: [
                    'closing_date' => $day,
                    'expected_balance' => $expected,
                    'counted_balance' => $counted,
                    'difference' => $closing->difference,
                ],
                description: 'إقفال الصندوق اليومي',
            );

            return $closing;
        });
    }
}
