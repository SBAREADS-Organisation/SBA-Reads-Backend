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

            $user = $userQuery->first();
            // dd('User Password', $user->password, Hash::check($request->password, $user->password));
            if (! $user || ! Hash::check($request->password, $user->password)) {
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
            } else {
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

        Mail::send('emails.otp', ['name' => $displayName, 'otp' => $otp], function ($message) use ($user) {
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
                'email' => 'required|email|exists:users,email',
            ], [
                'email.required' => 'Please enter your email address.',
                'email.email'    => 'Please enter a valid email address.',
                'email.exists'   => 'We couldn\'t find an account with that email address.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please check your email and try again.', 400, $validator->errors());
            }

            $user = User::where('email', $request->email)->first();

            // Generate a secure 6-digit OTP
            $otp = rand(100000, 999999);

            $value = [
                'otp' => $otp,
                'email' => $user->email,
            ];

            // Store OTP in Redis with a 10-minute expiration
            $key = "password_reset:otp:{$otp}";
            Cache::put($key, $value, now()->addMinutes(10));

            // Send OTP email
            $this->sendOtpEmail($user, $otp);

            return $this->success(null, 'OTP sent to your email', 200);
        } catch (\Exception $e) {
            // throw $th;
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
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
                // 'email' => 'required|email|exists:users,email',
                'otp' => 'required|digits:6',
            ], [
                'otp.required' => 'Please enter the OTP sent to your email.',
                'otp.digits'   => 'The OTP must be exactly 6 digits.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please enter a valid 6-digit OTP.', 400, $validator->errors());
            }

            // $user = User::where('email', $request->email)->first();
            $key = "password_reset:otp:{$request->otp}";

            // Verify OTP from Redis
            $storedOtp = Cache::get($key);
            logger($storedOtp);

            if (! $storedOtp || $storedOtp['otp'] != $request->otp) {
                return $this->error('Invalid or expired OTP', 400);
            }

            // Delete OTP from Redis after use
            Cache::forget($key);

            $email = $storedOtp['email'];

            // Reset Password permissions
            $reset_password_key = "reset_password_key:{$email}";
            Cache::put($reset_password_key, true, now()->addMinutes(60));

            return $this->success(null, 'OTP verified successfully', 200);
        } catch (\Throwable $th) {
            // throw $th;
            // dd($th->getMessage());
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        } catch (\Exception $e) {
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        }
    }

    /**
     * Reset password using OTP
     */
    public function resetPassword(Request $request)
    {
        try {
            // Validate Input
            $validator = Validator::make($request->all(), [
                'email' => ['required', 'email', 'exists:users,email'],
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
                'email.exists'      => 'We couldn\'t find an account with that email address.',
                'password.required' => 'Please enter a new password.',
                'password.min'      => 'Your password must be at least 8 characters long.',
                'password.confirmed' => 'The passwords you entered do not match.',
                'password.regex'    => 'Your password must include at least one uppercase letter, one lowercase letter, one number, and one special character.',
            ]);

            if ($validator->fails()) {
                return $this->error('Please fix the errors below and try again.', 400, $validator->errors());
            }

            // Verify reset password permissions
            $reset_password_key = "reset_password_key:{$request->email}";
            if (! Cache::get($reset_password_key)) {
                return $this->error('Invalid or expired password reset request', 400);
            }

            // Delete reset password permissions
            $user = User::where('email', $request->email)->first();

            // Hash and update the password
            $user->update([
                'password' => Hash::make($request->password),
            ]);

            // Delete OTP from Redis after use
            Cache::forget($reset_password_key);

            // Send reset confirmation email
            $this->sendPasswordResetConfirmationEmail($user);

            return $this->success(null, 'Password reset successful', 200);
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        } catch (\Exception $e) {

            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        }
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
