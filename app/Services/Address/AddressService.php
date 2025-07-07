<?php

namespace App\Services\Address;

use App\Models\Address;
use App\Traits\ApiResponse;
use Illuminate\Support\Facades\DB;

class AddressService
{
    use ApiResponse;

    public function create($user, $payload)
    {
        DB::beginTransaction();
        try {
            // Check if the user has a default address
            $defaultAddress = Address::where('user_id', $user->id)->where('is_default', true)->first();

            if (($payload['is_default'] ?? false)) {
                // If a default address exists, set it to false
                if ($defaultAddress) {
                    $defaultAddress->update(['is_default' => false]);
                }
            }

            $address = Address::create([
                'user_id' => $user->id,
                'full_name' => $payload['full_name'] ?? '',
                'address' => $payload['address'],
                'city' => $payload['city'],
                'region' => $payload['region'],
                'country' => $payload['country'],
                'postal_code' => $payload['postal_code'],
                'phone_number' => $payload['phone_number'],
                'is_default' => $payload['is_default'] ?? false,
            ]);

            DB::commit();

            return $this->success($address, 'Address created successfully.');
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->error('Failed to create address: ' . $th->getMessage(), 500, null, $th);
        }
    }

    public function getAll($user)
    {
        try {
            $query = Address::where('user_id', $user->id);

            // Apply search if a query parameter is provided
            if (request()->has('search')) {
                $search = request()->get('search');
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%")
                        ->orWhere('region', 'like', "%{$search}%")
                        ->orWhere('country', 'like', "%{$search}%")
                        ->orWhere('postal_code', 'like', "%{$search}%")
                        ->orWhere('phone_number', 'like', "%{$search}%");
                });
            }

            // Paginate the results
            $addresses = $query->paginate(10);

            if ($addresses->isEmpty()) {
                return $this->error('No addresses found.', 404);
            }

            return $this->success($addresses, 'Addresses retrieved successfully.');
        } catch (\Throwable $th) {
            return $this->error('Failed to retrieve addresses: ' . $th->getMessage(), 500, null, $th);
        }
    }
}
