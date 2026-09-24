<?php

namespace App\Console\Commands;

use App\Models\RecurringExpense;
use App\Services\ExpenseService;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Turns due recurring-expense templates into real expenses.
 *
 * Templates flagged auto_post are booked straight into the ledger; the rest
 * only raise a notification so a human decides. That split is deliberate — a
 * center should not discover an unexpected expense in its P&L.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'expenses:generate-recurring
                            {--dry-run : List what would be generated without writing anything}';

    protected $description = 'إنشاء المصاريف المتكررة المستحقة وتنبيه المحاسبين';

    public function handle(ExpenseService $expenses, NotificationService $notifications): int
    {
        $dryRun = $this->option('dry-run');
        $posted = 0;
        $flagged = 0;

        $templates = RecurringExpense::query()->active()->with('category')->get();

        foreach ($templates as $template) {
            if (! $template->isDue()) {
                continue;
            }

            $due = $template->nextDueDate();

            if (! $due) {
                continue;
            }

            if ($dryRun) {
                $this->line("سيتم إنشاء: {$template->name} — {$template->amount} ({$due->toDateString()})");
                $posted++;

                continue;
            }

            if ($template->auto_post) {
                $expense = $expenses->fromRecurring($template, $due->toDateString());
                $this->info("تم تسجيل المصروف {$expense->reference} — {$template->name}");
                $posted++;
            } else {
                $notifications->recurringExpenseDue($template);
                $flagged++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? "سيتم إنشاء {$posted} مصروف."
            : "تم تسجيل {$posted} مصروف، والتنبيه على {$flagged} مصروف بانتظار المراجعة.");

        return self::SUCCESS;
    }
}
