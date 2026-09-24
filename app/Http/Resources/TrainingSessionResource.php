<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainingSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'is_past' => $this->resource->isPast(),

            'trainee' => $this->whenLoaded('trainee', fn () => [
                'id' => $this->trainee?->uuid,
                'full_name' => $this->trainee?->full_name,
                'phone' => $this->trainee?->phone,
                'trainee_number' => $this->trainee?->trainee_number,
            ]),

            'trainer' => $this->whenLoaded('trainer', fn () => [
                'id' => $this->trainer?->uuid,
                'full_name' => $this->trainer?->full_name,
            ]),

            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->uuid,
                'name' => $this->vehicle->name,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),

            'evaluation' => $this->when($this->status === 'completed', fn () => [
                'overall_rating' => $this->overall_rating,
                'strengths' => $this->strengths,
                'weaknesses' => $this->weaknesses,
                'trainer_notes' => $this->trainer_notes,
                'next_requirements' => $this->next_requirements,
                'completed_at' => $this->completed_at?->toIso8601String(),
            ]),

            'skills' => $this->whenLoaded('skills', fn () => $this->skills->map(fn ($entry) => [
                'skill_id' => $entry->training_skill_id,
                'name' => $entry->skill?->name_ar,
                'rating' => $entry->rating,
                'note' => $entry->note,
            ])->all()),

            'cancellation_reason' => $this->when(
                in_array($this->status, ['cancelled', 'postponed'], true),
                fn () => $this->cancellation_reason,
            ),
        ];
    }
}
