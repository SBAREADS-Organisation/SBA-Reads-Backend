<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Services\Subscription\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    protected $service;

    public function __construct(SubscriptionService $service)
    {
        $this->service = $service;
    }

    public function subscribe(Request $request)
    {
        try {
            $validation = Validator::make($request->all(), [
                'subscription_id' => 'required|exists:subscriptions,id'
            ]);

            // Check if validation fails
            if ($validation->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validation->errors()
                );
            }

            return $this->service->subscribe($request->user(), $request->subscription_id);
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th);
            return $this->error(
                'An error occurred while subscribing to the plan.',
                500,
                config('app.debug') ? $th->getMessage() : null,
                $th
            );
        }
    }

    public function history(Request $request)
    {
        try {
            $subscriptions = $request->user()->subscriptions()->with('subscription')->latest()->get();

            if ($subscriptions->isEmpty()) {
                return $this->error(
                    'No subscriptions found.',
                    404
                );
            }

            return $this->success(
                $subscriptions,
                'User subscriptions retrieved successfully.'
            );
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error(
                'An error occurred while retrieving subscriptions.',
                500,
                null,
                $th
            );
        }
    }

    // Get all available subscriptions
    public function available(Request $request)
    {
        try {
            $subscriptions = $this->service->getAllAvailableSubscriptions();

            if ($subscriptions->isEmpty()) {
                return $this->error(
                    'No subscriptions found.',
                    404
                );
            }

            return $this->success(
                $subscriptions,
                'Available subscriptions retrieved successfully.'
            );
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error(
                'An error occurred while retrieving subscriptions.',
                500,
                null,
                $th
            );
        }
    }

    // Get a specific subscription by ID
    public function show(Request $request, $id)
    {
        try {
            $subscription = $this->service->getSubscriptionById($id);

            if (!$subscription) {
                return $this->error(
                    'Subscription not found.',
                    404
                );
            }

            return $this->success(
                $subscription,
                'Subscription retrieved successfully.',
                200
            );
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error(
                'An error occurred while retrieving the subscription.',
                500,
                null,
                $th
            );
        }
    }

    // Create a new subscription
    public function store(Request $request)
    {
        try {
            // use Validation::make() to validate the request
            $validation = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'duration_in_days' => 'nullable|integer|min:1',
                // perks is a json field, so we can use the json rule
                'perks' => 'nullable|array',
                'perks.*' => 'string|distinct',
                'model' => 'required|in:monthly,yearly',
                // currencies as array of strings
                'currencies' => 'nullable|array',
                'currencies.*' => 'string|distinct'
            ]);

            if ($validation->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validation->errors()
                );
            }

            $payload = (object) $validation->validated();

            // duration_in_days should be an integer and at least 1 day automatically assign this if the model is monthly 30, if yearly 365
            if ($payload->model == 'monthly') {
                $payload->duration_in_days = 30;
            } elseif ($payload->model == 'yearly') {
                $payload->duration_in_days = 365;
            }

            $subscription = $this->service->createSubscription($payload);

            return $this->success(
                $subscription,
                'Subscription created successfully.',
                201
            );
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th);
            return $this->error(
                'An error occurred while creating the subscription.',
                500,
                null,
                $th
            );
        }
    }

    // Update a subscription
    public function update(Request $request, $id)
    {
        try {
            $subscription = $this->service->getSubscriptionById($id);

            if (!$subscription) {
                return $this->error(
                    'Subscription not found.',
                    404
                );
            }

            // use Validation::make() to validate the request
            $validation = Validator::make($request->all(), [
                'title' => 'required|string|max:255',
                'price' => 'required|numeric|min:0',
                'duration_in_days' => 'nullable|integer|min:1',
                // perks is a json field, so we can use the json rule
                'perks' => 'nullable|json',
                'model' => 'required|in:monthly,yearly'
            ]);

            if ($validation->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validation->errors()
                );
            }

            $payload = (object) $validation->validated();

            // duration_in_days should be an integer and at least 1 day automatically assign this if the model is monthly 30, if yearly 365
            if ($payload->model == 'monthly') {
                $payload->duration_in_days = 30;
            } elseif ($payload->model == 'yearly') {
                $payload->duration_in_days = 365;
            }

            $subscription = $this->service->updateSubscription($subscription, $payload);

            return $this->success(
                $subscription,
                'Subscription updated successfully.',
                200
            );
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error(
                'An error occurred while updating the subscription.',
                500,
                config('app.debug') ? $th->getMessage() : null,
                $th
            );
        }
    }

    // Delete a subscription
    public function destroy(Request $request, $id)
    {
        try {
            $subscription = $this->service->getSubscriptionById($id);

            if (!$subscription) {
                return $this->error(
                    'Subscription not found.',
                    404
                );
            }

            $this->service->deleteSubscription($subscription);

            return $this->success(
                null,
                'Subscription deleted successfully.',
                200
            );
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error(
                'An error occurred while deleting the subscription.',
                500,
                null,
                $th
            );
        } catch (\Exception $e) {
            return $this->error(
                'An error occurred while deleting the subscription.',
                500,
                null,
                $e
            );
        }
    }
}
