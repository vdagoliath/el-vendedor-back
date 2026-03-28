<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Business;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'backoffice_role' => $this->resource->getEffectiveBackofficeRole()?->value,
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),
            'is_platform_admin' => $this->resource->is_platform_admin,
            'current_business' => $this->whenLoaded('currentBusiness', function (): ?array {
                $business = $this->resource->currentBusiness;

                if (! $business) {
                    return null;
                }

                return [
                    'id' => $business->id,
                    'name' => $business->name,
                    'slug' => $business->slug,
                    'is_active' => $business->is_active,
                ];
            }),
            'businesses' => $this->whenLoaded('businesses', function (): array {
                return $this->resource->businesses
                    ->map(function (Business $business): array {
                        return [
                            'id' => $business->id,
                            'name' => $business->name,
                            'slug' => $business->slug,
                            'is_active' => $business->is_active,
                            'role' => $business->pivot->role,
                            'membership_is_active' => (bool) $business->pivot->is_active,
                        ];
                    })
                    ->all();
            }),
        ];
    }
}
