<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'locale' => $this->locale,
            'status' => $this->status,
            'is_super_admin' => (bool) $this->is_super_admin,
            'branch' => $this->whenLoaded('branch', fn () => [
                'id' => $this->branch?->uuid,
                'name' => $this->branch?->name,
                'code' => $this->branch?->code,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'name' => $role->name,
                'label' => $role->label_ar,
            ])->all()),
            // The app uses these to decide which screens to show; the server
            // still enforces every one of them on each request.
            'permissions' => $this->permissionNames()->all(),
            'trainer_id' => $this->whenLoaded('trainer', fn () => $this->trainer?->uuid),
            'trainee_id' => $this->whenLoaded('trainee', fn () => $this->trainee?->uuid),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
        ];
    }
}
