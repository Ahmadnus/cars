<?php

namespace App\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Package;
use App\Models\Trainee;
use App\Models\TraineePackage;
use Illuminate\Support\Facades\DB;

/**
 * Selling training packages.
 *
 * Enrolling copies the package's terms onto the trainee's contract and credits
 * the lesson ledger once. Later edits to the catalogue price never reach an
 * existing enrolment.
 */
class PackageService
{
    public function __construct(
        protected TraineeBalanceService $balances,
        protected AuditLogger $audit,
    ) {
    }

    /**
     * @param  array{discount_amount?:float, discount_reason?:string|null, started_on?:string, notes?:string|null}  $options
     */
    public function assign(Trainee $trainee, Package $package, array $options = []): TraineePackage
    {
        return DB::transaction(function () use ($trainee, $package, $options) {
            if ($trainee->isClosed()) {
                throw BusinessRuleException::make('لا يمكن إسناد باقة لمتدرب منتهٍ أو ملغى.');
            }

            $gross = round((float) $package->price, 2);
            $discount = round((float) ($options['discount_amount'] ?? 0), 2);

            $this->assertDiscountAllowed($package, $gross, $discount, $options['discount_reason'] ?? null);

            $startedOn = $options['started_on'] ?? now()->toDateString();
            $expiresOn = $package->validity_days
                ? \Carbon\Carbon::parse($startedOn)->addDays($package->validity_days)->toDateString()
                : null;

            $enrolment = TraineePackage::create([
                'branch_id' => $trainee->branch_id,
                'trainee_id' => $trainee->id,
                'package_id' => $package->id,
                'package_name' => $package->name,
                'lessons_count' => $package->lessons_count,
                'lesson_duration_minutes' => $package->lesson_duration_minutes,
                'unit_price' => $package->pricePerLesson(),
                'extra_lesson_price' => $package->extra_lesson_price,
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'discount_reason' => $options['discount_reason'] ?? null,
                'extras_amount' => 0,
                'total_amount' => round($gross - $discount, 2),
                'paid_amount' => 0,
                'started_on' => $startedOn,
                'expires_on' => $expiresOn,
                'status' => 'active',
                'notes' => $options['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->balances->creditPackage($enrolment, $package->lessons_count);

            if ($trainee->status === 'new') {
                $trainee->update(['status' => 'in_training']);
            }

            $this->audit->logCreate('package.assigned', $enrolment, "إسناد باقة {$package->name} للمتدرب");

            if ($discount > 0) {
                $this->audit->log(
                    action: 'package.discount_granted',
                    subject: $enrolment,
                    after: ['gross' => $gross, 'discount' => $discount, 'total' => $enrolment->total_amount],
                    reason: $options['discount_reason'] ?? null,
                );
            }

            return $enrolment->fresh();
        });
    }

    /**
     * Sell additional lessons on top of an enrolment.
     *
     * Credits the ledger and raises the contract total, so the trainee's
     * outstanding balance reflects the extras immediately.
     */
    public function addExtraLessons(TraineePackage $enrolment, int $lessons, ?float $unitPrice = null): TraineePackage
    {
        return DB::transaction(function () use ($enrolment, $lessons, $unitPrice) {
            if ($lessons <= 0) {
                throw BusinessRuleException::make('عدد الحصص الإضافية يجب أن يكون أكبر من صفر.');
            }

            if ($enrolment->status === 'cancelled') {
                throw BusinessRuleException::make('لا يمكن إضافة حصص إلى باقة ملغاة.');
            }

            $price = round($unitPrice ?? (float) $enrolment->extra_lesson_price, 2);
            $cost = round($price * $lessons, 2);
            $original = $enrolment->getOriginal();

            $enrolment->forceFill([
                'extras_amount' => round((float) $enrolment->extras_amount + $cost, 2),
                'total_amount' => round((float) $enrolment->total_amount + $cost, 2),
                'status' => 'active',
            ])->save();

            $this->balances->creditExtraLessons(
                $enrolment,
                $lessons,
                "{$lessons} حصة إضافية بسعر {$price} للحصة",
            );

            $this->audit->logUpdate('package.extra_lessons_added', $enrolment, $original);

            return $enrolment->fresh();
        });
    }

    /** Change the catalogue price — audited, because it is a commercial decision. */
    public function updateCatalogPrice(Package $package, float $price, float $extraLessonPrice, string $reason): Package
    {
        return DB::transaction(function () use ($package, $price, $extraLessonPrice, $reason) {
            $original = $package->getOriginal();

            $package->update([
                'price' => round($price, 2),
                'extra_lesson_price' => round($extraLessonPrice, 2),
            ]);

            $this->audit->logUpdate('package.price_changed', $package, $original, $reason);

            return $package->fresh();
        });
    }

    public function cancel(TraineePackage $enrolment, string $reason): TraineePackage
    {
        return DB::transaction(function () use ($enrolment, $reason) {
            if ((float) $enrolment->paid_amount > 0) {
                throw BusinessRuleException::make(
                    'لا يمكن إلغاء باقة تم تحصيل دفعات عليها. يجب إلغاء الدفعات أولاً.',
                );
            }

            $original = $enrolment->getOriginal();
            $enrolment->update(['status' => 'cancelled']);

            $this->audit->logUpdate('package.cancelled', $enrolment, $original, $reason);

            return $enrolment->fresh();
        });
    }

    protected function assertDiscountAllowed(Package $package, float $gross, float $discount, ?string $reason): void
    {
        if ($discount < 0) {
            throw BusinessRuleException::make('قيمة الخصم لا يمكن أن تكون سالبة.');
        }

        if ($discount > $gross) {
            throw BusinessRuleException::make('قيمة الخصم لا يمكن أن تتجاوز سعر الباقة.');
        }

        if ($discount > 0 && blank($reason)) {
            throw BusinessRuleException::make(
                'يجب إدخال سبب الخصم.',
                ['discount_reason' => ['سبب الخصم مطلوب.']],
            );
        }

        $maxPercent = (float) $package->max_discount_percent;

        if ($maxPercent > 0 && $gross > 0) {
            $percent = $discount / $gross * 100;

            if ($percent - $maxPercent > 0.01) {
                throw BusinessRuleException::make(
                    sprintf('الخصم يتجاوز الحد المسموح لهذه الباقة (%.0f%%).', $maxPercent),
                    ['discount_amount' => ['الخصم أعلى من الحد المسموح.']],
                );
            }
        }
    }
}
