<?php

namespace App\Http\Controllers\KYC;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Slack\SlackWebhookService;
use App\Services\Stripe\StripeConnectService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Stripe\Account as StripeAccount;
use Stripe\Webhook;

class KYCController extends Controller
{
    protected $stripe;

    protected $kycService;

    public function __construct(StripeConnectService $stripe /* KYCVerificationService $kycService */)
    {
        $this->stripe = $stripe;
    }

    private function getValidCountryCodes()
    {
        return [
            'US',
            'NG',
            'GB',
            'CA',
            'IN',
            'FR',
            'DE',
            'IT',
            'ES',
            'BR',
            'AU',
            'ZA',
            // Add other country codes as necessary
        ];
    }

    public function initiate_KYC(Request $request)
    {
        try {
            $user = Auth::user();

            // Prepare combined DOB for validation
            $request->merge([
                'dob_combined' => "{$request->input('dob.year')}-{$request->input('dob.month')}-{$request->input('dob.day')}",
            ]);

            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'dob.day' => 'required|integer|min:1|max:31',
                'dob.month' => 'required|integer|min:1|max:12',
                'dob.year' => 'required|integer|digits:4',
                'dob_combined' => [
                    'required',
                    'date_format:Y-n-j',
                    function ($attribute, $value, $fail) use ($request) {
                        try {
                            $dob = Carbon::create($request->dob['year'], $request->dob['month'], $request->dob['day']);
                            if ($dob->isFuture()) {
                                $fail('Date of birth cannot be in the future.');
                            }
                            if ($dob->greaterThan(Carbon::now()->subYears(18))) {
                                $fail('You must be at least 18 years old.');
                            }
                        } catch (\Exception $e) {
                            $fail('Invalid date of birth provided.');
                        }
                    },
                ],
                'address.line1' => 'required|string|max:255',
                'address.city' => 'required|string|max:255',
                'address.postal_code' => 'required|string|max:20',
                'address.state' => 'required|string|size:2',
                'gender' => 'required|in:male,female,other',
                'phone' => 'required|string|max:20',
                'country' => ['required', 'size:2', 'alpha', Rule::in($this->getValidCountryCodes())],
            ]);
            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            // Early exit if already verified (checking the *current* user object)
            if ($user->kyc_status === 'verified') {
                return $this->success(
                    [
                        'account_id' => $user->kyc_account_id,
                        'status' => $user->kyc_status,
                    ],
                    'KYC already verified',
                    200
                );
            }

            // Early exit if already requires document upload
            if ($user->kyc_status === 'document-required') {
                return $this->error('KYC already requires document upload', 400);
            }

            // --- Call the Stripe Service to Create or Update Account ---
            // The service is responsible for determining create/update and saving to DB.
            $stripeAccountResponse = null;
            if (! $user->kyc_account_id) {
                // If user doesn't have an account ID, call the creation method
                $stripeAccountResponse = $this->stripe->createCustomAccount($request->all(), $user);
            } else {
                // If user has an account ID, call the update method
                $stripeAccountResponse = $this->stripe->updateCustomAccount($request->all(), $user);
            }

            // --- Handle the Stripe Service Response ---
            $finalAccountId = null;
            $finalAccountStatus = null;

            if ($stripeAccountResponse instanceof JsonResponse) {
                // This indicates an error response from your Stripe service
                $responseData = $stripeAccountResponse->getData(true);
                $errorDetails = $responseData['error'] ?? 'Unknown error from Stripe service.';

                return $this->error(
                    'Stripe API Error',
                    $stripeAccountResponse->getStatusCode(),
                    config('app.debug') ? $errorDetails : null
                );
            } elseif ($stripeAccountResponse instanceof StripeAccount) {
                // This indicates a successful Stripe API call
                $finalAccountId = $stripeAccountResponse->id;
                $user->refresh();
                $finalAccountStatus = $user->kyc_status;
            } else {
                // This should ideally not happen if your service returns only StripeAccount or JsonResponse
                Log::error('Unexpected return type from Stripe service', ['response' => $stripeAccountResponse]);

                return $this->error(
                    'Internal Server Error: Unexpected Stripe service response.',
                    500,
                    config('app.debug') ? 'Stripe service returned an unhandled type.' : null
                );
            }

            // --- Final Success Response ---
            return $this->success(
                [
                    'account_id' => $finalAccountId,
                    'status' => $finalAccountStatus,
                ],
                'KYC initiated/updated successfully',
                200
            );
        } catch (\Throwable $th) {
            Log::error('KYC initiation error for user '.(Auth::id() ?? 'N/A').': '.$th->getMessage(), ['exception' => $th, 'trace' => $th->getTraceAsString()]);

            return $this->error(
                'Error initiating KYC',
                500,
                config('app.debug') ? $th->getMessage() : null,
                $th
            );
        }
    }

    public function uploadDocument(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ]);

            // Only allow if status is document-required
            $user = Auth::user();

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            $user = Auth::user();
            $file = $request->file('document');
            $filePath = $file->store('stripe_uploads');

            $absolutePath = storage_path("app/private/{$filePath}");

            // Hanlde file upload error
            $response = $this->stripe->uploadIdentityDocument($user, $absolutePath);
            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $responseData = $response->getData(true);

                if (isset($responseData['error'])) {
                    return $this->error(
                        $responseData['message'] ?? 'Error uploading document',
                        400,
                        config('app.debug') ? $responseData : null
                    );
                }
            }

            return $this->success(
                $response,
                'Document uploaded successfully, verification pending.',
                200
            );
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error(
                'Error uploading document',
                500,
                $th->getMessage(),
                $th
            );
        }
    }

    public function kycStatus()
    {
        $user = Auth::user();

        return $this->success(
            [
                'status' => $user->kyc_status,
            ],
            'KYC status retrieved successfully',
            200
        );
    }

    public function handle(Request $request)
    {
        $payload = $request->getContent();
        // $sigHeader = $request->header('Stripe-Signature');
        $sigHeader = $request->server('HTTP_STRIPE_SIGNATURE', '') ?? '';
        $secret = trim(config('services.stripe.webhook_secret'));

        SlackWebhookService::send(
            '📦 New Webhook Event',
            [
                'payload' => $payload,
                'header' => $sigHeader,
                'secret' => $secret,
            ],
            'success'
        );

        // Log::info('┏━━━━ ⭐ Stripe Webhook Debug ⭐ ━━━━┓');
        // Log::info('Payload RAW:',   [ 'payload'   => $payload ]);
        // Log::info('Sig Header:',    [ 'header'    => $sigHeader ]);
        // Log::info('Secret (len):',  [ 'secret'    => $secret, 'length' => strlen($secret) ]);
        // Log::info('┗━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━┛');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $secret);
        } catch (\UnexpectedValueException $e) {
            // Log::error('Stripe Webhook payload invalid', ['error' => $e->getMessage()]);
            return $this->error(
                'Invalid payload',
                400,
                null
            );
            // return response('Invalid payload', 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            // Log::error('Stripe Webhook payload invalid JSON', [
            //     'error'    => $e->getMessage(),
            //     'payload'  => $payload,
            // ]);
            return $this->error(
                'Invalid payload',
                400,
                null
            );
            // return response()->json(['error' => 'Invalid payload'], 400);
        }

        if ($event->type === 'account.updated') {
            $account = $event->data->object;

            $user = User::where('kyc_account_id', $account->id)->first();

            if ($user) {
                if ($account->individual->verification->status === 'unverified' && $account->individual->verification->document->front === null) {
                    $user->update(['kyc_status' => 'document-required']);
                } elseif ($account->individual->verification->status === 'unverified' && $account->individual->verification->document->front !== null) {
                    $user->update(['kyc_status' => 'rejected']);
                } elseif ($account->individual->verification->status === 'pending' && $account->individual->verification->document->front !== null) {
                    $user->update(['kyc_status' => 'in-review']);
                } elseif ($account->individual->verification->status === 'pending' && $account->individual->verification->document->front === null) {
                    $user->update(['kyc_status' => 'document-required']);
                } elseif ($account->individual->verification->status === 'verified') {
                    // update users information and professional_profiles
                    $user->update([
                        'kyc_status' => 'verified',
                        'first_name' => $account->individual->first_name,
                        'last_name' => $account->individual->last_name,
                        'name' => $account->individual->first_name.' '.$account->individual->last_name,
                        // 'dob' => $account->individual->dob,
                        // 'address' => $account->individual->address,
                    ]);
                    // $user->professional_profile()->update([
                    //     'company_name' => $account->individual->company_name,
                    //     'job_title' => $account->individual->job_title,
                    //     'bio' => $account->individual->bio,
                    //     'website' => $account->individual->website,
                    // ]);
                    // $user->professional_profile()->updateOrCreate([
                    //     'user_id' => $user->id,
                    // ], [
                    //     'company_name' => $account->individual->company_name,
                    //     'job_title' => $account->individual->job_title,
                    //     'bio' => $account->individual->bio,
                    //     'website' => $account->individual->website,
                    // ]);
                    $user->update(['kyc_status' => 'verified']);
                }
            }
        }

        return $this->success(
            null,
            'Webhook handled successfully',
            200
        );
        // return response()->json(['status' => 'ok'], 200);
    }
}
