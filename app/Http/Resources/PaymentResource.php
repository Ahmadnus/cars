<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'receipt_number' => $this->receipt_number,
            'amount' => (float) $this->amount,
            'paid_on' => $this->paid_on?->toDateString(),
            'status' => $this->status,
            'source' => $this->source,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'code' => $this->paymentMethod?->code,
                'label' => $this->paymentMethod?->label_ar,
            ]),
            'trainee' => $this->whenLoaded('trainee', fn () => [
                'id' => $this->trainee?->uuid,
                'full_name' => $this->trainee?->full_name,
            ]),
            'package_name' => $this->whenLoaded('traineePackage', fn () => $this->traineePackage?->package_name),
        ];
    }
}
