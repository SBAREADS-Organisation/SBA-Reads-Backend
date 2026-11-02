<?php

namespace App\Http\Resources\User;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The only author fields that may appear on a book for people other than
 * the author themselves and staff. Never add contact, KYC or security data here.
 */
class PublicAuthorResource extends JsonResource
{
    public function toArray($request)
    {
        $picture = is_array($this->profile_picture) ? $this->profile_picture : [];

        return [
            'id' => $this->id,
            'account_type' => $this->account_type,
            'name' => $this->name,
            'first_name' => $this->first_name ?? '',
            'last_name' => $this->last_name ?? '',
            'username' => $this->username,
            'bio' => $this->bio ?? '',
            'pronouns' => $this->pronouns ?? '',
            'socials' => is_array($this->profile_info) ? ($this->profile_info['socials'] ?? []) : [],
            'profile_picture' => [
                'public_id' => is_numeric($picture['public_id'] ?? null) ? (int) $picture['public_id'] : 0,
                'public_url' => is_string($picture['public_url'] ?? null) ? $picture['public_url'] : '',
            ],
        ];
    }
}
