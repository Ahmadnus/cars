<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'plate_number' => $this->plate_number,
            'model' => $this->model,
            'year' => $this->year,
            'transmission' => $this->transmission,
            'status' => $this->status,
        ];
    }
}
