<?php

namespace App\Services\KYC;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use App\Models\KYCVerification;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class KYCVerificationService
{
    protected $stripe;
    protected Client $client;
    protected string $provider;

    public function __construct(StripeConnectService $stripe)
    {
        $this->client = new Client([
            'headers' => ['Authorization' => 'Bearer ' . env('KYC_API_KEY')]
        ]);
        $this->provider = env('KYC_PROVIDER');

        $this->stripe = $stripe;
    }

    /**
     *
     */
    public function initiate_KYC($payload)
    {
        $user = Auth::user();

        if (!$user->kyc_account_id) {
            $this->stripe->createCustomAccount($payload,$user);
        }

        return response()->json([
            'message' => 'KYC started',
            'status' => $user->kyc_status,
            'data' => [],
            'code' => 200
        ]);
    }

    public function uploadDocument(Request $request)
    {
        $request->validate([
            'document' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120'
        ]);

        $user = Auth::user();
        $file = $request->file('document');
        $filePath = $file->store('stripe_uploads');

        $absolutePath = storage_path("app/{$filePath}");

        $this->stripe->uploadIdentityDocument($user, $absolutePath);

        return response()->json(['message' => 'Document uploaded, verification pending.']);
    }

    public function kycStatus()
    {
        $user = Auth::user();

        return response()->json([
            'status' => $user->kyc_status,
        ]);
    }

    /**
     * Start a new KYC verification process.
     *
     * @param array $data
     * @return KYCVerification
     */
    public function initiateKYC($user)
    {
        $url = $this->getProviderURL();
        try {
            $response = $this->client->post($url, [
                'json' => [
                    'user_id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'dob' => $user->dob
                ]
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return KYCVerification::create([
                'user_id' => $user->id,
                'status' => 'pending',
                'provider' => $this->provider,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            // Log::error("KYC initiation failed: " . $e->getMessage());
            throw new \Exception("KYC initiation failed: " . $e->getMessage(), 0, $e);
        }
    }

    private function getProviderURL(): string
    {
        return match ($this->provider) {
            'sumsub' => 'https://api.sumsub.com/applicants',
            'trulioo' => 'https://gateway.trulioo.com/kyc',
            default => throw new \Exception("Unsupported KYC Provider"),
        };
    }

    /**
     * Complete a KYC verification process.
     *
     * @param KYCVerification $kycVerification
     * @param array $data
     * @return KYCVerification
     */
    public function completeVerification(KYCVerification $kycVerification, array $data): KYCVerification
    {
        $kycVerification->update([
            'status' => 'completed',
            'completed' => true,
            'data' => $data,
        ]);

        return $kycVerification;
    }
}
