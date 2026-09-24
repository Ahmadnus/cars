<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trainee's enrolment. Lesson counts are always present; money is only
 * included for callers allowed to see a trainee's financial position.
 */
class TraineePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isSelf = $user?->trainee?->id === $this->trainee_id;
        $canSeeMoney = $isSelf || ($user?->hasPermission('trainees.financial') ?? false);

        return [
            'id' => $this->uuid,
            'package_name' => $this->package_name,
            'lessons_count' => $this->lessons_count,
            'lesson_duration_minutes' => $this->lesson_duration_minutes,
            'started_on' => $this->started_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'status' => $this->status,
            'remaining_lessons' => $this->resource->remainingLessons(),
            'completed_lessons' => $this->resource->completedLessons(),
            'progress_percent' => $this->resource->progressPercent(),

            $this->mergeWhen($canSeeMoney, fn () => [
                'total_amount' => (float) $this->total_amount,
                'discount_amount' => (float) $this->discount_amount,
                'paid_amount' => (float) $this->paid_amount,
                'remaining_amount' => $this->resource->remainingAmount(),
            ]),
        ];
    }
}
