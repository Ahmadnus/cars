<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'reference' => $this->reference,
            'title' => $this->title,
            'amount' => (float) $this->amount,
            'spent_on' => $this->spent_on?->toDateString(),
            'status' => $this->status,
            'beneficiary' => $this->beneficiary,
            'invoice_number' => $this->invoice_number,
            'category' => $this->whenLoaded('category', fn () => [
                'code' => $this->category?->code,
                'name' => $this->category?->name_ar,
                'bucket' => $this->category?->profit_bucket,
            ]),
            'payment_method' => $this->whenLoaded('paymentMethod', fn () => [
                'code' => $this->paymentMethod?->code,
                'label' => $this->paymentMethod?->label_ar,
            ]),
        ];
    }
}
