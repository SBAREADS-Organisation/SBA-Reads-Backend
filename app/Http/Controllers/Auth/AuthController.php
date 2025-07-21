<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\Login\LoginNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        try {
            // Validate Input
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'password' => 'required|string|min:8',
                'account_type' => 'nullable|string|in:author,reader,admin,superadmin',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
            }

            // Check if User Exists
            $userQuery = User::where('email', $request->email);

            if ($request->has('account_type')) {
                $userQuery->where('account_type', $request->account_type);
            }

            $user = $userQuery->first();
            // dd('User Password', $user->password, Hash::check($request->password, $user->password));
            if (! $user || ! Hash::check($request->password, $user->password)) {
                return $this->error('Invalid credentials', 401);
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

            // Update Last Login Timestamp
            $user->update([
                'last_login_at' => Carbon::now(),
            ]);

            // Generate Authentication Token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Send Login Notification Email
            $this->sendLoginNotification($user, $request->ip()); // NOTE: Uncomment

            // // Log::info('User Login', [
            //     'user_id' => $user->id,
            //     'email' => $user->email,
            //     'ip_address' => $request->ip(),
            //     'timestamp' => now()->toDateTimeString(),
            // ]);

            $this->notifySlack(
                '🔐 User Login Detected',
                [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'ip_address' => $request->ip(),
                    'timestamp' => now()->toDateTimeString(),
                ],
                'info'
            );

            return $this->success([
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'token' => $token,
                'account_type' => $user->account_type,
            ], 'Login successful', 200);
        } catch (\Exception $e) {
            // dd($e);
            // dd($e->getMessage());
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        } catch (\Throwable $th) {
            // dd($th);
            // Log the exception message
            // Log::error('Error in login: ' . $th->getMessage());
            return $this->error('An error occurred while processing your request.', 500, $th->getMessage(), $th);
        }
    }

    private function sendLoginNotification($user, $ipAddress)
    {
        // $details = [
        //     'subject' => 'New Login Detected',
        //     'body' => "Your account was logged in from IP: $ipAddress at " . now()->toDateTimeString(),
        //     'name' => $user->name != 'NO NAME' ?? $user->name,
        // ];

        Mail::to($user->email)->queue(new LoginNotification($user, 'email', $ipAddress));

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
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
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
            // Log the exception message
            // Log::error('Error in forgotPassword: ' . $th->getMessage());
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
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
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
            // Log the exception message
            // Log::error('Error in verifyOtp: ' . $e->getMessage());
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
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed', 400, $validator->errors());
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
            // Log the exception message
            // Log::error('Error in resetPassword: ' . $e->getMessage());
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
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
            // Log the exception message
            // Log::error('Error in logout: ' . $e->getMessage());
            return $this->error('An error occurred while processing your request.', 500, $e->getMessage(), $e);
        }
    }

    /**
     * Send OTP email
     */
    private function sendOtpEmail($user, $otp)
    {
        $details = [
            'subject' => 'Password Reset OTP',
            'body' => "Your OTP for password reset is: $otp. This OTP expires in 10 minutes.",
            'name' => $user->name ?? 'User',
        ];

        Mail::send('emails.otp', $details, function ($message) use ($user) {
            $message->to($user->email)->subject('Password Reset OTP');
        });
    }

    /**
     * Send password reset confirmation email
     */
    private function sendPasswordResetConfirmationEmail($user)
    {
        $details = [
            'subject' => 'Password Reset Successful',
            'body' => 'Your password was successfully reset on '.Carbon::now()->toDateTimeString(),
            'name' => $user->name ?? 'User',
        ];

        Mail::send('emails.password_reset', $details, function ($message) use ($user) {
            $message->to($user->email)->subject('Password Reset Successful');
        });
    }
}
