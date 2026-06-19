<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class TwoFactorController extends Controller
{
    public function __construct(protected TotpService $totp) {}

    public function setup(Request $request): JsonResponse
    {
        $user   = $request->user();
        $secret = $this->totp->generateSecret();

        Cache::put("2fa_setup:{$user->id}", $secret, now()->addMinutes(10));

        return $this->success([
            'secret'          => $secret,
            'otpauth_url'     => $this->totp->otpauthUrl('SBA Reads', $user->email, $secret),
            'already_enabled' => ! empty($user->mfa_secret),
        ], 'Scan the QR code with your authenticator app, then verify with the 6-digit code.');
    }

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

        [$plaintext, $hashed] = $this->generateRecoveryCodes();

        $user->update([
            'mfa_secret'          => $secret,
            'mfa_recovery_codes'  => $hashed,
        ]);
        Cache::forget("2fa_setup:{$user->id}");

        return $this->success([
            'two_factor_enabled' => true,
            'recovery_codes'     => $plaintext,
        ], 'Two-factor authentication is now active. Save your recovery codes in a safe place.');
    }

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

        $user->update(['mfa_secret' => null, 'mfa_recovery_codes' => null]);

        return $this->success(['two_factor_enabled' => false], 'Two-factor authentication has been disabled.');
    }

    public function status(Request $request): JsonResponse
    {
        return $this->success(
            ['enabled' => ! empty($request->user()->mfa_secret)],
            '2FA status retrieved.'
        );
    }

    /**
     * Generate 8 single-use recovery codes.
     *
     * Each code is 10 characters formatted as XXXXX-XXXXX using an
     * unambiguous character set (no 0/O or 1/I confusion).
     * Returns [plaintext[], hashed[]] — store hashed, show plaintext once.
     */
    private function generateRecoveryCodes(int $count = 8): array
    {
        $chars     = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $plaintext = [];
        $hashed    = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) {
                $raw .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $formatted   = substr($raw, 0, 5) . '-' . substr($raw, 5);
            $plaintext[] = $formatted;
            $hashed[]    = Hash::make($formatted);
        }

        return [$plaintext, $hashed];
    }
}
