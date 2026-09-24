<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SkillEvaluationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'skill_id' => $this->training_skill_id,
            'skill_name' => $this->whenLoaded('skill', fn () => $this->skill?->name_ar),
            'level' => $this->level,
            'score' => $this->resource->score(),
            'note' => $this->note,
            'evaluated_at' => $this->evaluated_at?->toIso8601String(),
        ];
    }
}
