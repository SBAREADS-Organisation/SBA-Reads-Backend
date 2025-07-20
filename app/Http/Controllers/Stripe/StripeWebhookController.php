<?php

namespace App\Http\Controllers\Stripe;

use App\Http\Controllers\Controller;
use App\Services\Stripe\StripeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
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
     * @param Request $request
     * @return Response
     */
    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $secret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (SignatureVerificationException $e) {
            // Invalid signature (e.g., tampered payload, incorrect secret)
            Log::error('Stripe Webhook Signature Verification Failed: ' . $e->getMessage(), [
                'payload_start' => substr($payload, 0, 200), // Log partial payload for debugging
                'signature_header' => $sigHeader,
                'secret_configured' => substr($secret, 0, 10) . '...' // Log part of secret for debug (don't log full secret)
            ]);
            return response()->json(['error' => 'Webhook signature verification failed.'], 403);
        } catch (UnexpectedValueException $e) {
            // Invalid payload (e.g., not valid JSON)
            Log::error('Stripe Webhook Invalid Payload: ' . $e->getMessage(), [
                'payload_start' => substr($payload, 0, 200),
            ]);
            return response()->json(['error' => 'Invalid webhook payload.'], 400);
        } catch (\Throwable $e) { // Catch any other unexpected errors during construction
            Log::error('Stripe Webhook Construction Error: ' . $e->getMessage(), [
                'exception' => $e,
                'payload_start' => substr($payload, 0, 200),
            ]);
            return response()->json(['error' => 'Internal webhook error.'], 500);
        }

        Log::info('Stripe Webhook Received: ' . $event->type, ['event_id' => $event->id]);

        switch ($event->type) {
            case 'payment_intent.succeeded':
                $paymentIntent = $event->data->object;
                // Example: Fulfill the order, update order status, send confirmation email
                $this->service->handlePaymentIntentSucceeded($paymentIntent);
                break;

            case 'payment_intent.payment_failed':
                $paymentIntent = $event->data->object;
                // Example: Notify user of failed payment, update order status
                $this->handlePaymentIntentFailed($paymentIntent);
                break;

            case 'customer.subscription.created':
                $subscription = $event->data->object;
                // Example: Activate user's subscription in your DB
                $this->handleSubscriptionCreated($subscription);
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object;
                // Example: Update subscription status (e.g., cancelled, trial_ending)
                $this->handleSubscriptionUpdated($subscription);
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object;
                // Example: Deactivate user's subscription in your DB
                $this->handleSubscriptionDeleted($subscription);
                break;

            default:
                Log::warning('Stripe Webhook: Unhandled event type received: ' . $event->type, ['event_id' => $event->id]);
                break;
        }

        // 3. Always return a 200 OK response to Stripe
        // This indicates that your endpoint successfully received the event.
        return response()->json(['status' => 'success'], 200);
    }
}
