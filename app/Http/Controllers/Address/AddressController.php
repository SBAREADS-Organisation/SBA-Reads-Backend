<?php

namespace App\Http\Controllers\Address;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Traits\ApiResponse;
use App\Services\Address\AddressService;
use Illuminate\Support\Facades\Validator;

class AddressController extends Controller
{
    //
    use ApiResponse;
    protected $service;

    public function __construct(AddressService $service)
    {
        $this->service = $service;
    }

    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                // 'user_id' => 'required|exists:users,id',
                'full_name' => 'nullable|string|max:255',
                'address' => 'required|string|max:500',
                'city' => 'required|string|max:100',
                'region' => 'nullable|string|max:100',
                'country' => 'required|string|max:100',
                'postal_code' => 'required|string|max:20',
                'phone_number' => 'nullable|string|max:20|regex:/^\+?[0-9\s\-]{7,20}$/',
                'is_default' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            };

            return $this->service->create($request->user(), $request);
        } catch (\Throwable $th) {
            $message = $th->getMessage() ?? 'An error occurred while creating address.';
            return $this->error($message, 500, null, $th);
        }
    }

    public function addresses(Request $request)
    {
        try {
            return $this->service->getAll($request->user());
        } catch (\Throwable $th) {
            $message = $th->getMessage() ?? 'An error occurred while retrieving addresses.';
            return $this->error($message, 500, null, $th);
        }
    }
}
