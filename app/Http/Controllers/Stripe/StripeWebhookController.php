<?php

namespace App\Http\Controllers\Stripe;

use App\Http\Controllers\Controller;
use App\Models\WebhookEvent;
use App\Services\Stripe\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    protected StripeWebhookService $service;

    public function __construct(StripeWebhookService $stripeWebhookService)
    {
        $this->service = $stripeWebhookService;
    }

    /**
     * Handle incoming Stripe webhook requests.
     *
     * @return Response
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        $webhookEvent = null; // Initialize to null

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);

            $webhookEvent = WebhookEvent::updateOrCreate(
                ['stripe_event_id' => $event->id], // Unique identifier
                [
                    'type' => $event->type,
                    'payload' => $event->toArray(), // Store full payload
                    'status' => 'received', // Initial status
                ]
            );
        } catch (SignatureVerificationException $e) {
            $webhookEvent?->update([
                'status' => 'failed',
                'error_message' => 'Signature verification failed: ' . $e->getMessage(),
            ]);

            return response()->json(['error' => 'Webhook signature verification failed.'], 403);
        } catch (UnexpectedValueException $e) {
            $webhookEvent?->update([
                'status' => 'failed',
                'error_message' => 'Invalid payload: ' . $e->getMessage(),
            ]);

            return response()->json(['error' => 'Invalid webhook payload.'], 400);
        } catch (\Throwable $e) { // Catch any other unexpected errors during construction
            $webhookEvent?->update([
                'status' => 'failed',
                'error_message' => 'Internal error: ' . $e->getMessage(),
            ]);

            return response()->json(['error' => 'Internal webhook error.'], 500);
        }
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                // Example: Fulfill the order, update order status, send confirmation email
                $this->service->handlePaymentIntentSucceeded($paymentIntent);
                break;

            case 'account.updated':
                $account = $event->data->object;
                $this->service->handleAccountUpdated($account);
                break;

            default:
                break;
        }

        if ($webhookEvent) {
            $webhookEvent->update(['status' => 'processed']);
        }

        return response()->json(['status' => 'success'], 200);
    }
}
