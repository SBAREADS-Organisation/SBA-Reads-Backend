<?php

namespace App\Http\Controllers\Socials;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
/**
 * @method static \Laravel\Socialite\Contracts\Provider stateless()
 */
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use App\Mail\Login\LoginNotification;

class SocialAuthController extends Controller
{
    public function redirectToProvider()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleProviderCallback()
    {
        $user = Socialite::driver('google')->user();
        $authUser = User::firstOrCreate([
            'email' => $user->getEmail(),
        ], [
            'name' => $user->getName(),
            // 'password' => bcrypt(Str::random(16)),
        ]);

        return response()->json(['token' => $authUser->createToken('API Token')->plainTextToken]);
    }

    /**
     * Redirect the user to the OAuth provider.
     */
    public function redirect($provider)
    {
        if (!in_array($provider, ['google', 'facebook'])) {
            return response()->json([
                'data' => null,
                'code' => 400,
                'message' => 'Invalid provider'
            ], 400);
        }

        return response()->json([
            'url' => Socialite::driver($provider)->stateless()->redirect()->getTargetUrl()
        ]);
    }

    /**
     * Handle the OAuth provider callback.
     */
    public function callback($provider)
    {
        try {
            try {
                $socialUser = Socialite::driver($provider)->stateless()->user();
            } catch (\Exception $e) {
                return response()->json(['error' => 'Failed to authenticate'], 401);
            }

            // Check if user already exists
            $user = User::where('email', $socialUser->getEmail())->first();

            if (!$user) {
                // Create a new user
                $user = User::create([
                    'name' => $socialUser->getName(),
                    'email' => $socialUser->getEmail(),
                    'password' => Hash::make(Str::random(12)),
                    'account_type' => 'reader',
                    'default_login' => $provider,
                ]);

                // Assign a default role
                $user->assignRole('user');
            }

            // Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            // Update last login timestamp
            $user->update(['last_login_at' => now()]);

            // Send Login Notification
            Mail::to($user->email)->send(new LoginNotification($user, $provider, request()->ip()));

            return response()->json([
                'data' => [
                    'user' => $user,
                    'token' => $token,
                    'provider' => $provider,
                ],
                'code' => 200,
                'message' => 'Login successful',
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'data' => null,
                'code' => 500,
                'message' => 'Authentication failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
