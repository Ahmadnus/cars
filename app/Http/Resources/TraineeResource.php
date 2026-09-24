<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A trainee as the mobile apps see them.
 *
 * Financial fields are only included when the caller holds
 * `trainees.financial`, or when the caller *is* this trainee — a trainer
 * opening a trainee profile gets the training data and nothing about money.
 */
class TraineeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isSelf = $user?->trainee?->id === $this->id;
        $canSeeMoney = $isSelf || ($user?->hasPermission('trainees.financial') ?? false);

        return [
            'id' => $this->uuid,
            'trainee_number' => $this->trainee_number,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'license_type' => $this->license_type,
            'registration_date' => $this->registration_date?->toDateString(),
            'exam_date' => $this->exam_date?->toDateString(),
            'photo_url' => $this->photo_path ? \Storage::disk('public')->url($this->photo_path) : null,

            'trainer' => $this->whenLoaded('trainer', fn () => [
                'id' => $this->trainer?->uuid,
                'full_name' => $this->trainer?->full_name,
                'phone' => $this->trainer?->phone,
            ]),

            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->uuid,
                'name' => $this->branch?->name,
            ]),

            // Lesson balance is training data, not financial data.
            'balance' => $this->when(isset($this->balance_summary), fn () => $this->balance_summary),
            'progress_percent' => $this->when(isset($this->progress_percent), fn () => $this->progress_percent),

            $this->mergeWhen($canSeeMoney, fn () => [
                'financial' => [
                    'package_name' => $this->activePackageSnapshot()['name'] ?? null,
                    'total_amount' => $this->activePackageSnapshot()['total'] ?? null,
                    'paid_amount' => $this->activePackageSnapshot()['paid'] ?? null,
                    'remaining_amount' => $this->activePackageSnapshot()['remaining'] ?? null,
                ],
            ]),
        ];
    }

    /** @return array<string, mixed> */
    protected function activePackageSnapshot(): array
    {
        $package = $this->resource->activePackage();

        if (! $package) {
            return [];
        }

        return [
            'name' => $package->package_name,
            'total' => (float) $package->total_amount,
            'paid' => (float) $package->paid_amount,
            'remaining' => $package->remainingAmount(),
        ];
    }
}
