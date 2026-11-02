<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\Login\LoginNotification;
use App\Models\User;
use App\Services\Auth\TotpService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const RESET_OTP_MINUTES = 10;
    private const RESET_OTP_MAX_ATTEMPTS = 5;
    private const RESET_TOKEN_MINUTES = 15;

    public function __construct(protected TotpService $totp) {}

    public function login(Request $request)
    {
        try {
            // Validate Input
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:8',
                //'account_type' => 'nullable|string|in:author,reader,admin,superadmin',
            ], [
                'email.required' => 'Please enter your email address.',
                'email.email'    => 'Please enter a valid email address.',
                'password.required' => 'Please enter your password.',
                'password.min'      => 'Your password must be at least 8 characters.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please check your login details and try again.', 400, $validator->errors());
            }

            // Check if User Exists
            $userQuery = User::where('email', $request->email);

            // If account_type is provided, filter by it so the correct account
            // is returned when a user has both a reader and author account.
            if ($request->filled('account_type')) {
                $userQuery->where('account_type', $request->account_type);
            }

            // One email can hold a reader and an author account with different passwords.
            // Clients that don't send account_type sign in to the account the password fits.
            $accounts = $userQuery->orderBy('id')->get();
            $user = $accounts->first(fn (User $account) => $account->password && Hash::check($request->password, $account->password))
                ?? $accounts->first();

            if (! $user) {
                return $this->error('Invalid credentials', 400);
            }

            // Detect social-login accounts before running the password check so we
            // can return a specific message instead of the generic "wrong password".
            if (! in_array($user->default_login, ['email', 'password_only', null], true)
                && ! Hash::check($request->password, $user->password)) {
                $provider = ucfirst($user->default_login); // 'Google', 'Apple', …
                return $this->error(
                    "This account was originally created with {$provider}. "
                    . "To sign in with email and password, use 'Forgot Password' to set a password first.",
                    400
                );
            }

            if (! Hash::check($request->password, $user->password)) {
                return $this->error('Invalid credentials', 400);
            }

            // throw new \RuntimeException('Test Exception'); // Uncomment to test exception handling

            // NOTE: Check if Account is Suspended
            // if ($user->account_type === 'author') {
            //     if ($user->status === 'unverified') {
            //         return response()->json([
            //             'data' => null,
            //             'code' => 403,
            //             'message' => 'Your account is suspended. Contact support.',
            //         ], 403);
            //     }
            // }

            // Check if Account is Suspended
            if ($user->status === 'suspended') {
                return $this->error('Your account is suspended. Contact support.', 403);
            }

            // 2FA gate
            if (! empty($user->mfa_secret)) {
                // Layer 2A: Authenticator app (TOTP) — for users who have set it up
                $totpCode     = $request->input('totp_code');
                $recoveryCode = $request->input('recovery_code');

                if (! $totpCode && ! $recoveryCode) {
                    return response()->json(['two_factor_required' => true], 200);
                }

                if ($totpCode) {
                    if (! $this->totp->verify($user->mfa_secret, $totpCode)) {
                        return $this->error('Invalid authenticator code. Please try again.', 401);
                    }
                } else {
                    if (! $this->verifyAndConsumeRecoveryCode($user, $recoveryCode)) {
                        return $this->error('Invalid recovery code. Please try again.', 401);
                    }
                }
            } elseif ($user->default_login !== 'password_only') {
                // Layer 2B: Email OTP — mandatory for all users without an authenticator app
                $sessionToken = $this->generateLoginSession($user);
                return response()->json([
                    'email_otp_required' => true,
                    'session_token'      => $sessionToken,
                    'masked_email'       => $this->maskEmail($user->email),
                ], 200);
            }

            // Reached only for TOTP-verified users — issue token
            return $this->issueToken($user, $request->ip());
        } catch (\Exception $e) {
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        } catch (\Throwable $th) {

            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        }
    }

    // ── Email OTP login endpoints ─────────────────────────────────────────────

    public function verifyLoginOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_token' => 'required|string',
            'otp'           => 'required|string|digits:6',
        ]);

        if ($validator->fails()) {
            return $this->error('Invalid request.', 400, $validator->errors());
        }

        $sessionToken = $request->input('session_token');
        $otpInput     = $request->input('otp');

        $sessionData = Cache::get("login_session:{$sessionToken}");
        if (! $sessionData) {
            return $this->error('Your session has expired. Please log in again.', 401);
        }

        // Enforce attempt limit before comparing (prevents brute-force on 6 digits)
        $attemptKey = "login_otp_attempts:{$sessionToken}";
        $attempts   = (int) Cache::get($attemptKey, 0);

        if ($attempts >= 5) {
            Cache::forget("login_session:{$sessionToken}");
            Cache::forget("login_otp:{$sessionToken}");
            Cache::forget($attemptKey);
            return $this->error('Too many failed attempts. Please log in again.', 429);
        }

        $storedOtp = Cache::get("login_otp:{$sessionToken}");

        if (! $storedOtp || ! hash_equals($storedOtp, $otpInput)) {
            $newAttempts = $attempts + 1;
            Cache::put($attemptKey, $newAttempts, now()->addMinutes(10));
            $remaining = max(0, 4 - $attempts);
            return $this->error(
                "Incorrect code. {$remaining} " . ($remaining === 1 ? 'attempt' : 'attempts') . ' remaining.',
                401
            );
        }

        // OTP verified — clean up all session cache keys
        Cache::forget("login_session:{$sessionToken}");
        Cache::forget("login_otp:{$sessionToken}");
        Cache::forget($attemptKey);

        $user = User::find($sessionData['user_id']);
        if (! $user) {
            return $this->error('Account not found.', 404);
        }

        return $this->issueToken($user, $request->ip());
    }

    public function resendLoginOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'session_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error('Invalid request.', 400);
        }

        $sessionToken = $request->input('session_token');
        $sessionData  = Cache::get("login_session:{$sessionToken}");

        if (! $sessionData) {
            return $this->error('Your session has expired. Please log in again.', 401);
        }

        $user = User::find($sessionData['user_id']);
        if (! $user) {
            return $this->error('Account not found.', 404);
        }

        // Generate fresh OTP, reset attempt counter, and extend session TTL
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put("login_session:{$sessionToken}", $sessionData, now()->addMinutes(10));
        Cache::put("login_otp:{$sessionToken}", $otp, now()->addMinutes(10));
        Cache::forget("login_otp_attempts:{$sessionToken}");

        $this->sendLoginOtpEmail($user, $otp);

        return $this->success(null, 'A new verification code has been sent to your email.');
    }

    // ── Shared token issuance ─────────────────────────────────────────────────

    private function issueToken(User $user, string $ip)
    {
        $user->update(['last_login_at' => Carbon::now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->sendLoginNotification($user, $ip);

        $this->notifySlack(
            'User Login Detected',
            [
                'user_id'    => $user->id,
                'email'      => $user->email,
                'ip_address' => $ip,
                'timestamp'  => now()->toDateTimeString(),
            ],
            'info'
        );

        return $this->success([
            'user_id'      => $user->id,
            'email'        => $user->email,
            'role'         => $user->getRoleNames()->first(),
            'token'        => $token,
            'account_type' => $user->account_type,
            'redirect_to'  => in_array($user->account_type, ['manager', 'superadmin']) ? 'admin' : 'app',
        ], 'Login successful', 200);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function generateLoginSession(User $user): string
    {
        $sessionToken = (string) Str::uuid();
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        Cache::put("login_session:{$sessionToken}", ['user_id' => $user->id], now()->addMinutes(10));
        Cache::put("login_otp:{$sessionToken}", $otp, now()->addMinutes(10));

        $this->sendLoginOtpEmail($user, $otp);

        return $sessionToken;
    }

    private function sendLoginOtpEmail(User $user, string $otp): void
    {
        $displayName = ($user->name && strtoupper(trim($user->name)) !== 'NO NAME')
            ? $user->name
            : ($user->username ?? 'there');

        Mail::send('emails.login_otp', ['name' => $displayName, 'otp' => $otp], function ($message) use ($user) {
            $message->to($user->email)->subject('Sign-in Verification Code — SBA Reads');
        });
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = explode('@', $email, 2);
        $len    = strlen($local);
        $masked = $len <= 2
            ? str_repeat('*', $len)
            : $local[0] . str_repeat('*', max(1, $len - 2)) . $local[$len - 1];
        return $masked . '@' . $domain;
    }

    private function sendLoginNotification($user, $ipAddress)
    {
        // $details = [
        //     'subject' => 'New Login Detected',
        //     'body' => "Your account was logged in from IP: $ipAddress at " . now()->toDateTimeString(),
        //     'name' => $user->name != 'NO NAME' ?? $user->name,
        // ];

        Mail::to($user->email)->send(new LoginNotification($user, 'email', $ipAddress));

        // Mail::send('emails.login_notification', $details, function ($message) use ($user) {
        //     $message->to($user->email)
        //         ->subject('New Login Detected');
        // });
    }

    public function forgotPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
            ], [
                'email.required' => 'Please enter your email address.',
                'email.email'    => 'Please enter a valid email address.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please check your email and try again.', 400, $validator->errors());
            }

            $user = User::where('email', $request->email)->first();

            // Same response whether or not the account exists, so this can't be used to discover who has one.
            if ($user) {
                $otp = (string) random_int(100000, 999999);
                $expiresAt = now()->addMinutes(self::RESET_OTP_MINUTES);

                Cache::put($this->resetOtpKey($request->email), [
                    'hash'       => $this->resetHash($otp),
                    'attempts'   => 0,
                    'expires_at' => $expiresAt->getTimestamp(),
                ], $expiresAt);

                if ($this->allowsLegacyReset()) {
                    Cache::put('password_reset:legacy_otp:' . $this->resetHash($otp), $user->email, $expiresAt);
                }

                $this->sendOtpEmail($user, $otp);
            }

            return $this->success(null, 'If an account exists for that email, we have sent it a code.', 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        }
    }

    /**
     * Verify password reset otp
     */
    public function verifyOtp(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => [$this->allowsLegacyReset() ? 'nullable' : 'required', 'email'],
                'otp'   => 'required|digits:6',
            ], [
                'email.required' => 'Please update your app to reset your password.',
                'otp.required'   => 'Please enter the OTP sent to your email.',
                'otp.digits'     => 'The OTP must be exactly 6 digits.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please enter a valid 6-digit OTP.', 400, $validator->errors());
            }

            $otp = (string) $request->otp;

            // Older app versions only send the code; see password_reset.allow_legacy_flow.
            if (! $request->filled('email')) {
                $legacyKey = 'password_reset:legacy_otp:' . $this->resetHash($otp);
                $email = Cache::pull($legacyKey);
                if (! $email) {
                    return $this->error('Invalid or expired OTP', 400);
                }
                Cache::forget($this->resetOtpKey($email));
                Cache::put($this->legacyResetKey($email), true, now()->addMinutes(self::RESET_OTP_MINUTES));

                return $this->success(null, 'OTP verified successfully', 200);
            }

            $key = $this->resetOtpKey($request->email);
            $record = Cache::get($key);

            if (! $record || $record['attempts'] >= self::RESET_OTP_MAX_ATTEMPTS) {
                Cache::forget($key);
                return $this->error('Invalid or expired OTP', 400);
            }

            if (! hash_equals($record['hash'], $this->resetHash($otp))) {
                $record['attempts']++;
                Cache::put($key, $record, Carbon::createFromTimestamp($record['expires_at']));
                return $this->error('Invalid or expired OTP', 400);
            }

            Cache::forget($key);
            Cache::forget('password_reset:legacy_otp:' . $record['hash']);

            $token = Str::random(64);
            Cache::put($this->resetTokenKey($request->email), $this->resetHash($token), now()->addMinutes(self::RESET_TOKEN_MINUTES));

            return $this->success(['reset_token' => $token], 'OTP verified successfully', 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        }
    }

    /**
     * Reset password
     */
    public function resetPassword(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email'],
                'reset_token' => [$this->allowsLegacyReset() ? 'nullable' : 'required', 'string'],
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'confirmed',
                    // Regex pattern: lookahead for lowercase, uppercase, digit, and special character
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/',
                ],
            ], [
                'email.required'    => 'Please enter your email address.',
                'email.email'       => 'Please enter a valid email address.',
                'reset_token.required' => 'Please update your app to reset your password.',
                'password.required' => 'Please enter a new password.',
                'password.min'      => 'Your password must be at least 8 characters long.',
                'password.confirmed' => 'The passwords you entered do not match.',
                'password.regex'    => 'Your password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please fix the errors below and try again.', 400, $validator->errors());
            }

            if (! $this->consumeResetPermission($request)) {
                return $this->error('Invalid or expired password reset request', 400);
            }

            $user = User::where('email', $request->email)->first();
            if (! $user) {
                return $this->error('Invalid or expired password reset request', 400);
            }

            $user->update([
                'password' => Hash::make($request->password),
            ]);

            // Anyone signed in with the old password, possibly an intruder, is signed out.
            $user->tokens()->delete();

            $this->sendPasswordResetConfirmationEmail($user);

            return $this->success(null, 'Password reset successful', 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        }
    }

    private function consumeResetPermission(Request $request): bool
    {
        if ($request->filled('reset_token')) {
            $key = $this->resetTokenKey($request->email);
            $expected = Cache::get($key);
            if (! $expected || ! hash_equals($expected, $this->resetHash($request->reset_token))) {
                return false;
            }
            Cache::forget($key);

            return true;
        }

        return $this->allowsLegacyReset() && (bool) Cache::pull($this->legacyResetKey($request->email));
    }

    private function allowsLegacyReset(): bool
    {
        return (bool) config('auth.password_reset.allow_legacy_flow');
    }

    private function resetHash(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function resetOtpKey(string $email): string
    {
        return 'password_reset:otp:' . $this->resetHash(strtolower(trim($email)));
    }

    private function resetTokenKey(string $email): string
    {
        return 'password_reset:token:' . $this->resetHash(strtolower(trim($email)));
    }

    private function legacyResetKey(string $email): string
    {
        return 'password_reset:legacy_allowed:' . $this->resetHash(strtolower(trim($email)));
    }

    /**
     * Verify an admin invite token and return the invited user's info.
     */
    public function verifyInvite(string $token)
    {
        $data = Cache::get("admin_invite:{$token}");

        if (! $data) {
            return $this->error('This invite link is invalid or has expired.', 404);
        }

        $user = User::find($data['user_id']);

        if (! $user || $user->status !== 'invited') {
            return $this->error('This invite has already been used or is no longer valid.', 400);
        }

        return $this->success([
            'email' => $data['email'],
            'name'  => $user->name,
            'role'  => $data['role'],
        ], 'Invite is valid.');
    }

    /**
     * Accept an admin invite: set password and activate the account.
     */
    public function acceptInvite(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'token'                 => 'required|string',
                'password'              => [
                    'required', 'string', 'min:8', 'confirmed',
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/',
                ],
                'password_confirmation' => 'required|string',
            ], [
                'password.regex' => 'Password must include uppercase, lowercase, a number, and a special character.',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            $data = Cache::get("admin_invite:{$request->token}");

            if (! $data) {
                return $this->error('This invite link is invalid or has expired.', 400);
            }

            $user = User::find($data['user_id']);

            if (! $user || $user->status !== 'invited') {
                return $this->error('This invite has already been used or is no longer valid.', 400);
            }

            $user->update([
                'password' => Hash::make($request->password),
                'status'   => 'active',
            ]);

            Cache::forget("admin_invite:{$request->token}");

            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->success([
                'user_id'      => $user->id,
                'email'        => $user->email,
                'role'         => $user->getRoleNames()->first(),
                'account_type' => $user->account_type,
                'token'        => $token,
                'redirect_to'  => 'admin',
            ], 'Account activated successfully. Welcome to SBA Reads Admin.');
        } catch (\Throwable $th) {
            return $this->error('An error occurred while activating your account.', 500, $th->getMessage(), $th);
        }
    }

    public function logout(Request $request)
    {
        try {
            $request->user()->tokens()->delete();

            return $this->success(null, 'Logged out successfully', 200);
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        } catch (\Exception $e) {
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        }
    }

    /**
     * Send OTP email
     */
    private function sendOtpEmail($user, $otp)
    {
        $displayName = ($user->name && $user->name !== 'NO NAME')
            ? $user->name
            : ($user->username ?? 'there');

        $data = [
            'name' => $displayName,
            'otp'  => $otp,
        ];

        Mail::send('emails.otp', $data, function ($message) use ($user) {
            $message->to($user->email)->subject('Password Reset OTP — SBA Reads');
        });
    }

    /**
     * Check a recovery code against the user's stored hashed codes.
     * Consumes the code on success so it cannot be reused.
     */
    private function verifyAndConsumeRecoveryCode(User $user, string $input): bool
    {
        $codes = $user->mfa_recovery_codes ?? [];
        if (empty($codes)) {
            return false;
        }

        foreach ($codes as $index => $hashed) {
            if (Hash::check($input, $hashed)) {
                unset($codes[$index]);
                $user->update(['mfa_recovery_codes' => array_values($codes)]);
                return true;
            }
        }

        return false;
    }

    /**
     * Send password reset confirmation email
     */
    private function sendPasswordResetConfirmationEmail($user)
    {
        $displayName = ($user->name && $user->name !== 'NO NAME')
            ? $user->name
            : ($user->username ?? 'there');

        $details = [
            'subject' => 'Password Reset Successful',
            'body' => 'Your password was successfully reset on ' . Carbon::now()->toDateTimeString(),
            'name' => $displayName,
        ];

        Mail::send('emails.password_reset', $details, function ($message) use ($user) {
            $message->to($user->email)->subject('Password Reset Successful');
        });
    }
}
