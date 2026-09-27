<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $time = substr((string) $this->requested_start_time, 0, 5);

        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'status' => $this->status,
            'requested_date' => $this->requested_date?->toDateString(),
            'requested_start_time' => $time !== '' ? $time : null,
            'trainee_note' => $this->trainee_note,
            'admin_note' => $this->admin_note,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            // The trainer's say, so both apps and the dashboard can show who
            // agreed rather than only that something changed.
            'trainer_decision' => $this->trainer_decision,
            'trainer_decision_label' => $this->trainerDecisionLabel(),
            'trainer_note' => $this->trainer_note,
            'trainer_decided_at' => $this->trainer_decided_at?->toIso8601String(),
            'trainer_decided_by' => $this->whenLoaded(
                'trainerDecider',
                fn () => $this->trainerDecider?->trainer?->full_name ?? $this->trainerDecider?->name,
            ),
            'awaiting_trainer' => $this->awaitingTrainer(),
            'ready_for_office' => $this->readyForOffice(),

            'created_at' => $this->created_at?->toIso8601String(),

            'trainee' => $this->whenLoaded('trainee', fn () => [
                'id' => $this->trainee?->uuid,
                'full_name' => $this->trainee?->full_name,
                'phone' => $this->trainee?->phone,
            ]),

            'preferred_trainer' => $this->whenLoaded('preferredTrainer', fn () => $this->preferredTrainer ? [
                'id' => $this->preferredTrainer->uuid,
                'full_name' => $this->preferredTrainer->full_name,
            ] : null),

            'session' => $this->whenLoaded('trainingSession', fn () => $this->trainingSession ? [
                'id' => $this->trainingSession->uuid,
                'scheduled_date' => $this->trainingSession->scheduled_date?->toDateString(),
                'start_time' => substr((string) $this->trainingSession->start_time, 0, 5),
            ] : null),
        ];
    }
}
