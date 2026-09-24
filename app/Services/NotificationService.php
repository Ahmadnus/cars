<?php

namespace App\Services;

use App\Models\EmployeeAdvance;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\RecurringExpense;
use App\Models\Trainee;
use App\Models\TraineePackage;
use App\Models\TrainingSession;
use App\Models\User;
use App\Models\UtilityBill;
use App\Notifications\SystemNotification;
use App\Services\Notifications\ChannelGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Raises every system notification.
 *
 * Callers say what happened, not how it is delivered. Routing (who should be
 * told) and channel selection (in-app now; SMS/WhatsApp/push once a gateway is
 * bound) are decided here, so the rules live in one place.
 */
class NotificationService
{
    /** @param array<int, ChannelGateway> $gateways */
    public function __construct(
        protected SettingsRepository $settings,
        protected array $gateways = [],
    ) {
    }

    // ------------------------------------------------------------------
    // Appointments
    // ------------------------------------------------------------------

    public function appointmentBooked(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.booked',
            'تم حجز حصة تدريبية',
            $this->sessionSentence($session, 'تم حجز حصة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    public function appointmentChanged(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.changed',
            'تم تعديل موعد الحصة',
            $this->sessionSentence($session, 'تم تعديل موعد الحصة إلى'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
            'warning',
        );
    }

    public function appointmentCancelled(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.cancelled',
            'تم إلغاء الحصة',
            $this->sessionSentence($session, 'تم إلغاء حصة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
            'error',
        );
    }

    public function appointmentReminder(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'appointment.reminder',
            'تذكير بحصة قادمة',
            $this->sessionSentence($session, 'لديك حصة قادمة'),
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    public function sessionEvaluated(TrainingSession $session): void
    {
        $this->notify(
            $this->peopleFor($session),
            'evaluation.created',
            'تم تسجيل تقييم جديد',
            "تم تسجيل تقييم لحصة المتدرب {$session->trainee->full_name}.",
            ['session_uuid' => $session->uuid],
            route('admin.sessions.show', $session, false),
        );
    }

    // ------------------------------------------------------------------
    // Booking requests
    // ------------------------------------------------------------------

    public function bookingRequestResolved(\App\Models\BookingRequest $request): void
    {
        $status = match ($request->status) {
            'approved' => 'تمت الموافقة على طلبك',
            'rejected' => 'تم رفض طلبك',
            'rescheduled' => 'تم إعادة جدولة طلبك',
            default => 'تم تحديث حالة طلبك',
        };

        $this->notify(
            collect([$request->trainee?->user])->filter(),
            'booking_request.resolved',
            $status,
            $request->admin_note ?: $status,
            ['request_uuid' => $request->uuid],
            route('admin.booking-requests.index', [], false),
            $request->status === 'rejected' ? 'warning' : 'success',
        );
    }

    // ------------------------------------------------------------------
    // Training balance
    // ------------------------------------------------------------------

    public function lowLessonBalance(TraineePackage $package, int $remaining): void
    {
        $trainee = $package->trainee;

        $this->notify(
            $this->staffFor($package->branch_id, 'appointments.view')->merge(collect([$trainee?->user])->filter()),
            'balance.low',
            'رصيد حصص منخفض',
            "تبقّى {$remaining} حصة فقط للمتدرب {$trainee?->full_name}.",
            ['trainee_package_uuid' => $package->uuid, 'remaining' => $remaining],
            route('admin.trainees.show', $trainee, false),
            'warning',
        );
    }

    // ------------------------------------------------------------------
    // Finance
    // ------------------------------------------------------------------

    public function paymentRecorded(Payment $payment): void
    {
        $this->notify(
            $this->staffFor($payment->branch_id, 'payments.view'),
            'payment.recorded',
            'تم تسجيل دفعة',
            "دفعة بقيمة {$payment->amount} من {$payment->trainee?->full_name} (إيصال {$payment->receipt_number}).",
            ['payment_uuid' => $payment->uuid],
            route('admin.payments.show', $payment, false),
            'success',
        );
    }

    public function payrollDue(Payroll $payroll): void
    {
        $this->notify(
            $this->staffFor($payroll->branch_id, 'payroll.pay'),
            'payroll.due',
            'راتب مستحق الصرف',
            "راتب {$payroll->employee?->full_name} لشهر {$payroll->period} مستحق الصرف.",
            ['payroll_uuid' => $payroll->uuid],
            route('admin.payroll.show', $payroll, false),
            'warning',
        );
    }

    public function recurringExpenseDue(RecurringExpense $expense): void
    {
        $this->notify(
            $this->staffFor($expense->branch_id, 'expenses.view'),
            'recurring_expense.due',
            'مصروف متكرر مستحق',
            "المصروف المتكرر «{$expense->name}» بقيمة {$expense->amount} أصبح مستحقاً.",
            ['recurring_expense_uuid' => $expense->uuid],
            route('admin.recurring-expenses.index', [], false),
            'warning',
        );
    }

    public function utilityBillDue(UtilityBill $bill): void
    {
        $this->notify(
            $this->staffFor($bill->branch_id, 'utilities.manage'),
            'utility_bill.due',
            'فاتورة خدمات مستحقة',
            "فاتورة {$bill->serviceLabel()} لشهر {$bill->billing_month} مستحقة بتاريخ {$bill->due_date->format('Y-m-d')}.",
            ['bill_uuid' => $bill->uuid],
            route('admin.utilities.index', [], false),
            'warning',
        );
    }

    public function advanceSettled(EmployeeAdvance $advance): void
    {
        $this->notify(
            $this->staffFor($advance->branch_id, 'advances.manage'),
            'advance.settled',
            'تم سداد سلفة بالكامل',
            "تم سداد سلفة الموظف {$advance->employee?->full_name} بالكامل.",
            ['advance_uuid' => $advance->uuid],
            route('admin.advances.index', [], false),
            'success',
        );
    }

    public function examScheduled(Trainee $trainee): void
    {
        $this->notify(
            $this->staffFor($trainee->branch_id, 'trainees.view')->merge(collect([$trainee->user])->filter()),
            'exam.scheduled',
            'موعد امتحان',
            "تم تحديد موعد امتحان للمتدرب {$trainee->full_name} بتاريخ {$trainee->exam_date?->format('Y-m-d')}.",
            ['trainee_uuid' => $trainee->uuid],
            route('admin.trainees.show', $trainee, false),
        );
    }

    // ------------------------------------------------------------------
    // Delivery
    // ------------------------------------------------------------------

    /** @param Collection<int, User> $recipients */
    public function notify(
        Collection $recipients,
        string $type,
        string $title,
        string $body,
        array $payload = [],
        ?string $url = null,
        string $level = 'info',
    ): void {
        $recipients = $recipients->filter()->unique('id')->values();

        if ($recipients->isEmpty()) {
            return;
        }

        $channels = $this->channelsFor();

        if (in_array('database', $channels, true) || in_array('mail', $channels, true)) {
            Notification::send(
                $recipients,
                new SystemNotification($type, $title, $body, $payload, $url, $level, $channels),
            );
        }

        // External providers are opt-in and fail soft: a dead SMS provider must
        // never roll back the business operation that raised the notification.
        foreach ($this->gateways as $gateway) {
            if (! $gateway->isEnabled()) {
                continue;
            }

            foreach ($recipients as $recipient) {
                try {
                    $gateway->send($recipient, $title, $body, $payload);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }
    }

    /** @return array<int, string> Laravel channels enabled by settings */
    protected function channelsFor(): array
    {
        $channels = [];

        if ($this->settings->bool('notifications.enable_in_app', true)) {
            $channels[] = 'database';
        }

        if ($this->settings->bool('notifications.enable_email', false)) {
            $channels[] = 'mail';
        }

        return $channels ?: ['database'];
    }

    /** Staff who should hear about something at a branch, filtered by permission. */
    protected function staffFor(int $branchId, string $permission): Collection
    {
        return User::query()
            ->where('status', 'active')
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                    ->orWhere('can_access_all_branches', true)
                    ->orWhereHas('branches', fn ($b) => $b->where('branches.id', $branchId));
            })
            ->with('roles.permissions', 'permissionOverrides')
            ->get()
            ->filter(fn (User $user) => $user->hasPermission($permission));
    }

    /** The trainee and trainer attached to a lesson, where they have logins. */
    protected function peopleFor(TrainingSession $session): Collection
    {
        $session->loadMissing('trainee.user', 'trainer.user');

        return collect([$session->trainee?->user, $session->trainer?->user])->filter();
    }

    protected function sessionSentence(TrainingSession $session, string $prefix): string
    {
        return sprintf(
            '%s بتاريخ %s الساعة %s مع المدرب %s.',
            $prefix,
            $session->scheduled_date->format('Y-m-d'),
            substr((string) $session->start_time, 0, 5),
            $session->trainer?->full_name ?? '—',
        );
    }
}
