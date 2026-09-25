<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;

/**
 * Clears out spent and expired passcodes.
 *
 * Rows are kept for a while after use so a disputed sign-in can be traced,
 * but they are credentials-adjacent and there is no reason to hold them
 * indefinitely.
 */
class PruneOtpCodes extends Command
{
    protected $signature = 'otp:prune {--days=7 : Delete codes older than this}';

    protected $description = 'Delete expired and consumed login passcodes';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = OtpCode::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("تم حذف {$deleted} رمز تحقق أقدم من {$days} يوم.");

        return self::SUCCESS;
    }
}
