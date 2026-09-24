<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrainerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'trainer_number' => $this->trainer_number,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'employment_date' => $this->employment_date?->toDateString(),
            'license_types' => $this->license_types ?? [],
            'working_days' => $this->working_days ?? [],
            'work_start_time' => substr((string) $this->work_start_time, 0, 5),
            'work_end_time' => substr((string) $this->work_end_time, 0, 5),
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->uuid,
                'name' => $this->branch?->name,
            ]),
            'trainees_count' => $this->whenCounted('trainees'),
            'vehicles' => $this->whenLoaded('vehicles', fn () => VehicleResource::collection($this->vehicles)),
        ];
    }
}
