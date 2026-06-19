<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class TwoFactorController extends Controller
{
    public function __construct(protected TotpService $totp) {}

    /**
     * Generate a fresh TOTP secret and return the QR code URI.
     * The secret is held in cache until the admin verifies it with enable().
     */
    public function setup(Request $request): JsonResponse
    {
        $user   = $request->user();
        $secret = $this->totp->generateSecret();

        // 10-minute window to complete setup
        Cache::put("2fa_setup:{$user->id}", $secret, now()->addMinutes(10));

        return $this->success([
            'secret'       => $secret,
            'otpauth_url'  => $this->totp->otpauthUrl('SBA Reads Admin', $user->email, $secret),
            'already_enabled' => ! empty($user->mfa_secret),
        ], 'Scan the QR code with your authenticator app, then verify with the 6-digit code.');
    }

    /**
     * Verify the code from the authenticator app and activate 2FA.
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|digits:6']);

        $user   = $request->user();
        $secret = Cache::get("2fa_setup:{$user->id}");

        if (! $secret) {
            return $this->error('Setup session expired. Please start again.', 422);
        }

        if (! $this->totp->verify($secret, $request->code)) {
            return $this->error('Invalid code — check your authenticator app and try again.', 422);
        }

        $user->update(['mfa_secret' => $secret]);
        Cache::forget("2fa_setup:{$user->id}");

        return $this->success(['two_factor_enabled' => true], 'Two-factor authentication is now active.');
    }

    /**
     * Disable 2FA. Requires a live TOTP code to confirm account ownership.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|digits:6']);

        $user = $request->user();

        if (empty($user->mfa_secret)) {
            return $this->error('Two-factor authentication is not enabled on your account.', 422);
        }

        if (! $this->totp->verify($user->mfa_secret, $request->code)) {
            return $this->error('Invalid code — check your authenticator app and try again.', 422);
        }

        $user->update(['mfa_secret' => null]);

        return $this->success(['two_factor_enabled' => false], 'Two-factor authentication has been disabled.');
    }

    /**
     * Return whether 2FA is currently enabled for the authenticated admin.
     */
    public function status(Request $request): JsonResponse
    {
        return $this->success(
            ['enabled' => ! empty($request->user()->mfa_secret)],
            '2FA status retrieved.'
        );
    }
}
