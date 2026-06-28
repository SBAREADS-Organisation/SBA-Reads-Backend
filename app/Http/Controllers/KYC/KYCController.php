<?php

namespace App\Http\Controllers\KYC;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessKYCVerificationJob;
use App\Models\User;
use App\Models\UserKycInfo;
use App\Services\Stripe\StripeConnectService;
use Carbon\Carbon;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Stripe\Account as StripeAccount;
// Countries where Stripe Connect identity verification works reliably.
// Authors from other countries go through manual admin KYC review.
const STRIPE_KYC_COUNTRIES = ['US', 'GB', 'CA', 'AU', 'FR', 'DE', 'IT', 'ES'];

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
                'first_name'          => 'required|string|max:255',
                'last_name'           => 'required|string|max:255',
                'dob.day'             => 'required|integer|min:1|max:31',
                'dob.month'           => 'required|integer|min:1|max:12',
                'dob.year'            => 'required|integer|digits:4',
                'dob_combined'        => [
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
                'address.line1'       => 'required|string|max:255',
                'address.city'        => 'required|string|max:255',
                'address.postal_code' => 'nullable|string|max:20',
                'address.state'       => 'nullable|string|max:50', // full state names for non-US countries
                'gender'              => 'required|in:male,female,other',
                'phone'               => 'required|string|max:20',
                'country'             => ['required', 'size:2', 'alpha', Rule::in($this->getValidCountryCodes())],
            ]);
            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            // Early exit if already verified, pending admin review, or currently in Stripe review
            if (in_array($user->kyc_status, ['verified', 'pending_manual', 'in-review'])) {
                $messages = [
                    'verified'       => 'KYC already verified.',
                    'pending_manual' => 'KYC is pending admin review.',
                    'in-review'      => 'Your identity is currently being reviewed — we\'ll notify you when complete.',
                ];
                return $this->success(
                    ['account_id' => $user->kyc_account_id, 'status' => $user->kyc_status],
                    $messages[$user->kyc_status],
                    200
                );
            }

            $country   = strtoupper($request->input('country'));
            $useStripe = in_array($country, STRIPE_KYC_COUNTRIES);
            $dob       = Carbon::create(
                $request->input('dob.year'),
                $request->input('dob.month'),
                $request->input('dob.day')
            )->toDateString();

            // Always persist KYC data locally so admin has a record regardless of path
            UserKycInfo::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'first_name'    => $request->input('first_name'),
                    'last_name'     => $request->input('last_name'),
                    'dob'           => $dob,
                    'address_line1' => $request->input('address.line1'),
                    'city'          => $request->input('address.city'),
                    'state'         => $request->input('address.state'),
                    'postal_code'   => $request->input('address.postal_code'),
                    'country'       => $country,
                    'phone'         => $request->input('phone'),
                    'gender'        => $request->input('gender'),
                ]
            );

            // ── Manual KYC path: Nigeria + other non-Stripe countries ─────────────
            if (! $useStripe) {
                $firstName = $request->input('first_name');
                $lastName  = $request->input('last_name');
                $user->update([
                    'kyc_status'       => 'pending_manual',
                    'ai_review_status' => null,
                    'first_name'       => $firstName,
                    'last_name'        => $lastName,
                    'name'             => trim("{$firstName} {$lastName}"),
                ]);

                // Dispatch AI verification — it will auto-approve or flag for manual review
                try {
                    ProcessKYCVerificationJob::dispatch($user->id)->onQueue('ai');
                } catch (\Throwable $e) {
                    Log::error('KYC job dispatch failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
                }

                return $this->success(
                    ['status' => 'pending_manual'],
                    'Your details have been submitted. We are verifying your identity — you will be notified shortly.',
                    200
                );
            }

            // ── Stripe KYC path: US, UK, CA, AU, EU ──────────────────────────────
            $stripeAccountResponse = $user->kyc_account_id
                ? $this->stripe->updateCustomAccount($request->all(), $user)
                : $this->stripe->createCustomAccount($request->all(), $user);

            if ($stripeAccountResponse instanceof JsonResponse) {
                $responseData = $stripeAccountResponse->getData(true);
                return $this->error(
                    'Stripe API Error',
                    $stripeAccountResponse->getStatusCode(),
                    config('app.debug') ? ($responseData['error'] ?? null) : null
                );
            }

            if (! ($stripeAccountResponse instanceof StripeAccount)) {
                return $this->error('Internal Server Error: Unexpected Stripe service response.', 500);
            }

            $user->refresh();

            return $this->success(
                ['account_id' => $stripeAccountResponse->id, 'status' => $user->kyc_status],
                'KYC initiated successfully.',
                200
            );
        } catch (\Throwable $th) {
            Log::error('KYC initiation failed', [
                'user_id' => optional(Auth::user())->id,
                'error'   => $th->getMessage(),
                'file'    => $th->getFile(),
                'line'    => $th->getLine(),
            ]);
            return $this->error(
                $th->getMessage(),
                500,
                config('app.debug') ? $th->getMessage() : null,
                $th
            );
        }
    }

    public function uploadDocument(Request $request)
    {
        $user     = Auth::user();
        $filePath = null;

        try {
            if ($user->kyc_provider !== 'stripe') {
                return $this->error('Document upload via this endpoint is only for Stripe-verified accounts.', 400);
            }

            if (! in_array($user->kyc_status, ['document-required', 'rejected'])) {
                return $this->error(
                    'Document upload is not required at this time. Your current status is: ' . $user->kyc_status,
                    400
                );
            }

            if (empty($user->kyc_account_id)) {
                return $this->error('Stripe account not found. Please complete your KYC details first.', 400);
            }

            $validator = Validator::make($request->all(), [
                'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            $file         = $request->file('document');
            $filePath     = $file->store('stripe_uploads');
            $absolutePath = storage_path("app/private/{$filePath}");

            $response = $this->stripe->uploadIdentityDocument($user, $absolutePath);

            if ($response instanceof \Illuminate\Http\JsonResponse) {
                $responseData = $response->getData(true);
                return $this->error(
                    $responseData['message'] ?? 'Error uploading document',
                    $response->getStatusCode(),
                    config('app.debug') ? $responseData : null
                );
            }

            return $this->success(
                null,
                'Document uploaded successfully. Your identity is now under review.',
                200
            );
        } catch (\Throwable $th) {
            Log::error('KYC Stripe document upload failed', [
                'user_id' => $user->id,
                'error'   => $th->getMessage(),
            ]);
            return $this->error('Error uploading document. Please try again.', 500, $th->getMessage(), $th);
        } finally {
            // Always clean up the temp file regardless of success or failure
            if ($filePath) {
                \Illuminate\Support\Facades\Storage::delete($filePath);
            }
        }
    }

    /**
     * Upload a verification document for manual KYC review (Nigeria / non-Stripe countries).
     *
     * Stripe authors use uploadDocument() instead (which uploads directly to Stripe).
     * This endpoint is for authors going through admin manual review.
     *
     * Accepted types: nin_slip, bvn_slip, passport, drivers_license, national_id
     */
    public function uploadManualDocument(Request $request): JsonResponse
    {
        try {
            $user = Auth::user();

            if (in_array($user->kyc_status, ['verified', 'rejected'])) {
                return $this->error('Document upload is not available for your current account status.', 400);
            }

            $validator = Validator::make($request->all(), [
                'document'      => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
                'document_type' => 'required|string|in:nin_slip,bvn_slip,passport,drivers_license,national_id',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            $kycInfo = UserKycInfo::where('user_id', $user->id)->first();
            if (! $kycInfo) {
                return $this->error('Please complete your KYC details first before uploading a document.', 400);
            }

            $file = $request->file('document');

            Configuration::instance([
                'cloud' => [
                    'cloud_name' => config('services.cloud.cloud_name'),
                    'api_key'    => config('services.cloud.api_key'),
                    'api_secret' => config('services.cloud.api_secret'),
                ],
                'url' => ['secure' => true],
            ]);

            $result   = (new UploadApi)->upload($file->getRealPath(), [
                'folder'        => 'kyc_documents',
                'resource_type' => 'auto',
                'public_id'     => 'kyc_' . $user->id . '_' . now()->timestamp,
            ]);
            $uploaded = $result['secure_url'];
            $publicId = $result['public_id'];

            $kycInfo->update([
                'document_type'        => $request->input('document_type'),
                'document_url'         => $uploaded,
                'document_public_id'   => $publicId ?? null,
                'document_uploaded_at' => now(),
            ]);

            Log::info('KYC manual document uploaded', [
                'user_id'       => $user->id,
                'document_type' => $request->input('document_type'),
            ]);

            // Re-trigger AI verification now that a document is available.
            // Document presence significantly boosts the AI confidence score.
            ProcessKYCVerificationJob::dispatch($user->id)->onQueue('ai');

            return $this->success(
                ['document_uploaded' => true, 'document_type' => $request->input('document_type')],
                'Document uploaded. We are re-verifying your identity — you will be notified shortly.',
                200
            );
        } catch (\Throwable $th) {
            Log::error('KYC manual document upload failed', [
                'user_id' => Auth::id(),
                'error'   => $th->getMessage(),
                'file'    => $th->getFile(),
                'line'    => $th->getLine(),
            ]);
            return $this->error(
                'Failed to upload document. Please try again.',
                500,
                config('app.debug') ? $th->getMessage() : null
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

}
