<?php

namespace App\Http\Controllers\KYC;

use App\Http\Controllers\Controller;
use App\Models\KYCVerification;
use App\Models\User;
// use App\Services\KYC\KYCVerificationService;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
// use PragmaRX\Countries\Package\Countries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Stripe\Webhook;
use App\Services\Slack\SlackWebhookService;

class KYCController extends Controller
{
    protected $stripe;
    protected $kycService;
    // $countries = new Countries();
    // $validCountryCodes = $countries->all()->pluck('cca2')->toArray();

    public function __construct(StripeConnectService $stripe, /*KYCVerificationService $kycService*/)
    {
        // $this->middleware('auth:sanctum');
        // $this->kycService = $kycService;
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

            $validator = Validator::make($request->all(), [
                'first_name' => 'required|string|max:255',
                'last_name' => 'required|string|max:255',
                'dob.day' => 'required|integer|min:1|max:31',
                'dob.month' => 'required|integer|min:1|max:12',
                'dob.year' => 'required|integer|digits:4|before_or_equal:' . now()->subYears(18)->year,
                // 'email' => 'required|email',
                // 'address' => 'required|string|max:500',
                'address.line1' => 'required|string|max:255',
                'address.city' => 'required|string|max:255',
                'address.postal_code' => 'required|string|max:20',
                'address.state' => 'required|string|size:2',
                'gender' => 'required|in:male,female,other',
                'phone' => 'required|string|max:20',
                'country' => 'required|size:2|alpha|in:' . implode(',', $this->getValidCountryCodes()),
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // dd($user->kyc_account_id);

            // Check if the user already has a KYC account verified
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

            // CHeck is status is requiring document upload
            if ($user->kyc_status === 'document-required') {
                return $this->error(
                    'KYC already requires document upload',
                    400,
                    null
                );
            }

            if (!$user->kyc_account_id) {
                $response = $this->stripe->createCustomAccount((object) $request->all(), $user);

                // dd($user->kyc_account_id, $response);

                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $responseData = $response->getData(true);
                    if (isset($responseData['error'])) {
                        return $this->error(
                            $responseData['error'],
                            400,
                            null
                        );
                    }
                }
            }

            if ($user->kyc_account_id) {
                $response = $this->stripe->updateCustomAccount((object) $request->all(), $user);
                // dd($response);
                if ($response instanceof \Illuminate\Http\JsonResponse) {
                    $responseData = $response->getData(true);
                    if (isset($responseData['error'])) {
                        return $this->error(
                            $responseData['error'],
                            400,
                            null
                        );
                    }
                }
            }

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

            return $this->success(
                [
                    'account_id' => $user->kyc_account_id,
                    'status' => $user->kyc_status,
                ],
                'KYC initiated successfully',
                200
            );
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th);
            return $this->error(
                'Error initiating KYC',
                500,
                null,
                $th
            );
        }
    }

    public function uploadDocument(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120'
            ]);

            // Only allow if status is document-required
            $user = Auth::user();
            // if ($user->kyc_status !== 'document-required') {
            //     return $this->error(
            //         'KYC does not require document upload',
            //         400,
            //         null
            //     );
            // }

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // dd($request->file());

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
                        $responseData['data'] ?? null
                    );
                }
            }

            return $this->success(
                $response,
                'Document uploaded successfully, verification pending.',
                200
            );
        } catch (\Throwable $th) {
            //throw $th;
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
                        'name' => $account->individual->first_name . ' ' . $account->individual->last_name,
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
