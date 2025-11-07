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
        // For authors, return comprehensive information
        if ($this->account_type === 'author') {
            return [
                'id' => $this->id,
                'account_type' => $this->account_type,
                'archived' => $this->archived ?? false,
                'created_at' => $this->created_at?->toISOString(),
                'default_login' => $this->default_login ?? '',
                'deleted' => $this->deleted ?? false,
                'device_token' => $this->device_token,
                'email' => $this->email,
                'email_verified_at' => $this->email_verified_at?->toISOString(),
                'first_name' => $this->first_name ?? '',
                'kyc_account_id' => $this->kyc_account_id ?? '',
                'kyc_customer_id' => $this->kyc_customer_id,
                'kyc_metadata' => $this->kyc_metadata ?? [],
                'kyc_provider' => $this->kyc_provider ?? '',
                'kyc_status' => $this->kyc_status ?? 'document-required',
                'kyc_info' => $this->relationLoaded('kycInfo') && $this->kycInfo ? [
                    'address_line1' => $this->kycInfo->address_line1 ?? '',
                    'address_line2' => $this->kycInfo->address_line2 ?? '',
                    'city' => $this->kycInfo->city ?? '',
                    'country' => $this->kycInfo->country ?? '',
                    'created_at' => $this->kycInfo->created_at?->toISOString(),
                    'dob' => $this->kycInfo->dob ?? '',
                    'first_name' => $this->kycInfo->first_name ?? '',
                    'gender' => $this->kycInfo->gender ?? '',
                    'id' => $this->kycInfo->id,
                    'last_name' => $this->kycInfo->last_name ?? '',
                    'phone' => $this->kycInfo->phone ?? '',
                    'postal_code' => $this->kycInfo->postal_code ?? '',
                    'state' => $this->kycInfo->state ?? '',
                    'updated_at' => $this->kycInfo->updated_at?->toISOString(),
                    'user_id' => $this->kycInfo->user_id,
                ] : null,
                'last_login_at' => $this->last_login_at?->toISOString(),
                'last_name' => $this->last_name ?? '',
                'mfa_secret' => $this->mfa_secret ?? '',
                'name' => $this->name,
                'username' => $this->username,
                'preferences' => $this->preferences ?? [],
                'bio' => $this->bio ?? '',
                'profile_info' => $this->profile_info ?? [],
                'profile_picture' => $this->formatProfilePicture($this->profile_picture ?? []),
                'settings' => $this->settings ?? [],
                'status' => $this->status ?? '',
                'updated_at' => $this->updated_at?->toISOString(),
            ];
        }

        // For non-authors (readers, etc.), return standard format
        return [
            'id' => $this->id,
            'name' => $this->name ?? 'NO NAME',
            'email' => $this->email,
            'account_type' => $this->account_type,
            'status' => $this->status,
            'kyc_status' => $this->kyc_status,
            'profile_picture' => $this->formatProfilePicture($this->profile_picture ?? []),
            'bio' => $this->bio,
            'preferences' => $this->preferences ?? [],
            'last_login_at' => $this->last_login_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'username' => $this->username,
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

    private function formatProfilePicture($profilePicture)
    {
        $rawId = $profilePicture['public_id'] ?? null;
        $rawUrl = $profilePicture['public_url'] ?? null;
        $publicId = is_numeric($rawId) ? (int) $rawId : null;
        $publicUrl = is_string($rawUrl) ? $rawUrl : null;

        return [
            'public_id' => (int) $publicId,
            'public_url' => (string) $publicUrl,
        ];
    }
}
