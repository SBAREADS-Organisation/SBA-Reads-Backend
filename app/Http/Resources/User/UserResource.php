<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'account_type' => $this->account_type,
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            'profile_picture' => $this->profile_picture,
            'bio' => $this->bio,
            'preferences' => $this->preferences,
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            // Return only IDs instead of full book objects
            'purchased_books' => $this->whenLoaded('purchasedBooks', function () {
                return $this->purchasedBooks->pluck('id')->toArray();
            }),

            // 'bookmarks' => $this->whenLoaded('bookmarks', function () {
            //     return $this->bookmarks->pluck('id')->toArray();
            // }),

            'kyc_info' => $this->whenLoaded('kycInfo'),
            'professional_profile' => $this->whenLoaded('professionalProfile'),
            'roles' => $this->whenLoaded('roles', function () {
                return $this->roles->pluck('name')->toArray();
            }),
        ];
    }
}
