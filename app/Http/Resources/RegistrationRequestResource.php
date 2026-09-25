<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RegistrationRequest
 */
class RegistrationRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'reference' => $this->reference,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'license_type' => $this->license_type,
            'city' => $this->city,
            'phone_verified' => $this->isVerified(),
            'decision_reason' => $this->decision_reason,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // Personal detail is included only for staff who may read it — the
            // public status endpoint uses the narrow shape below instead.
            $this->mergeWhen(
                $request->user()?->hasPermission('registrations.view') ?? false,
                fn () => [
                    'national_id' => $this->national_id,
                    'birth_date' => $this->birth_date?->toDateString(),
                    'gender' => $this->gender,
                    'address' => $this->address,
                    'secondary_phone' => $this->secondary_phone,
                    'notes' => $this->notes,
                    'trainee_number' => $this->trainee?->trainee_number,
                ],
            ),
        ];
    }

    /**
     * What the applicant themselves may see, holding only a reference.
     *
     * @return array<string, mixed>
     */
    public static function publicStatus(\App\Models\RegistrationRequest $request): array
    {
        return [
            'reference' => $request->reference,
            'full_name' => $request->full_name,
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'decision_reason' => $request->decision_reason,
            'submitted_at' => $request->created_at?->toIso8601String(),
            'decided_at' => $request->reviewed_at?->toIso8601String(),
        ];
    }
}
