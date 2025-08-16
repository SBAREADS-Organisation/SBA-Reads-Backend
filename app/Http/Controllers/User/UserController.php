<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\User\UserResource;
use App\Mail\Onboarding\WelcomeEmail;
use App\Mail\Registration\AuthorVerificationToken;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use App\Traits\ApiResponse;
use Error;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    use ApiResponse;

    protected $stripe;
    //

    public function __construct(StripeConnectService $stripe /* KYCVerificationService $kycService */)
    {
        // $this->middleware('auth:sanctum');
        // $this->kycService = $kycService;
        $this->stripe = $stripe;
    }

    public function index()
    {
        return response()->json([
            'data' => null,
            'code' => 200,
            'message' => 'You have reached the users APIs.',
        ], 200, []);
    }

    public function register(Request $request)
    {
        try {
            // dd($request->all());

            // Validation logic
            $validator = Validator::make($request->all(), [
                // 'fullname' => 'required|max:255',
                // 'username' => 'required|unique:users',
                'email' => 'required|unique:users',
                // 'country' => 'required',
                'password' => [
                    'required',
                    'string',
                    'min:8',
                    'regex:/[A-Z]/',
                    'regex:/[a-z]/',
                    'regex:/[0-9]/',
                    'regex:/[\W_]/',
                ],
                'account_type' => 'required|string|in:reader,author',
                // 'role' => 'string|exists:roles, name',
                // 'confirm_password' => 'required|same:password',
            ]);

            // NOTE: set fullname to concatenate first_name and last_name

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
                // return response()->json()->withErrors($validator)->withInput();
            }

            $accountType = $request->account_type;
            $email = $request->email;

            if ($accountType === 'author') {
                DB::beginTransaction();
                $cacheKey = "author_register_{$email}";
                $cached = Cache::get($cacheKey);

                // Check if email already exists as author
                $existingUser = User::where('email', $email)->first();
                if ($existingUser) {
                    return $this->error(
                        'Email already exists.',
                        409,
                        null
                    );
                }

                if ($cached) {
                    return $this->error(
                        'Action denied, you might have an active session.',
                        403,
                        null
                    );
                }

                $token = rand(1000, 9999);

                // Cache data for 10 minutes
                Cache::put($cacheKey, json_encode([
                    'email' => $email,
                    'password' => Hash::make($request->password),
                    'account_type' => $accountType,
                    'default_login' => 'email',
                    'token' => $token,
                ]), now()->addMinutes(10));

                // Send email with token (stub or actual)
                Mail::to($email)->queue(new AuthorVerificationToken($token));

                DB::commit();

                return $this->success(
                    ['email' => $email, 'otp' => $token],
                    'Verification token sent to your email. Please verify to continue.',
                    202
                );
            }

            // For readers - create account immediately
            if ($accountType === 'reader') {
                DB::beginTransaction();
                $user = new User;
                $user->fill([
                    // 'name' => $request->fullname,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'default_login' => 'email',
                    'account_type' => $request->account_type,
                    'status' => 'active',
                ]);
                $user->save();

                // Assign Role
                // $defaultRole = $request->account_type === 'author' ? 'author' : 'reader';
                $role = Role::where('name', 'user')->first();
                if ($role) {
                    $user->assignRole($role);
                } else {
                    // Delete user if already created when faced with error
                    $user->delete();
                    $user->refresh();

                    // dd($user, $role);
                    return $this->error(
                        'User creation failed, please contact support.',
                        400,
                        null
                    );
                }

                $customer = $this->stripe->createCustomer($user);

                if ($customer instanceof JsonResponse) {
                    $customerData = (array) $customer->getData();

                    if (isset($customerData['error'])) {
                        $user->delete();

                        return $this->error(
                            'Failed to create Stripe customer.',
                            400,
                            config('app.debug') ? $customerData['data'] : null
                        );
                    }
                }
                Mail::to($user->email)->queue(new WelcomeEmail($user->name ?? 'NO NAME', $user->account_type));

                $user->refresh();

                $token = $user->createToken('auth_token')->plainTextToken;

                DB::commit();

                return $this->success([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $user->getRoleNames()->first(),
                    'token' => $token,
                    'account_type' => $user->account_type,
                ], 'User registered successfully', 201);
            } else {
                return $this->error(
                    'Invalid account type. Only reader and author are allowed.',
                    400,
                    null
                );
            }
        } catch (\Exception $e) {
            DB::rollBack();

            return $this->error(
                'An error occurred while registering the user.',
                500,
                config('app.debug') ? $e->getMessage() : 'An error occurred while registering the user',
                $e
            );
        } catch (\Throwable $th) {
            DB::rollBack();

            return $this->error(
                'An error occurred while registering the user.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while registering the user',
                $th
            );
        }
    }

    // Create a super admin
    public function createSuperAdmin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users,email',
            'password' => [
                'required',
                'string',
                'min:8',
                'regex:/[A-Z]/',
                'regex:/[a-z]/',
                'regex:/[0-9]/',
                'regex:/[\W_]/',
            ],
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validator->errors()
            );
        }

        try {
            $user = new User;
            $user->email = $request->email;
            $user->password = Hash::make($request->password);
            $user->account_type = 'superadmin';
            $user->default_login = 'email';
            $user->save();

            // Assign superadmin role (create if not exists)
            $role = Role::firstOrCreate(['name' => 'superadmin']);
            $user->assignRole($role);

            $user->refresh();

            return $this->success([
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
            ], 'Super admin created successfully', 201);
        } catch (\Exception $e) {
            return $this->error(
                'An error occurred while creating the super admin.',
                500,
                config('app.debug') ? $e->getMessage() : 'An error occurred while creating the super admin.',
                $e
            );
        }
    }

    // Resend verification token
    public function resendVerificationToken(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validator->errors()
            );
        }

        // Check if email already exists as author
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            return $this->error(
                'Email already exists.',
                409,
                null
            );
        }

        $cacheKey = "author_register_{$request->email}";
        $cached = Cache::get($cacheKey);

        if (! $cached) {
            return $this->error(
                'Verification expired or not found.',
                410,
                null
            );
        }

        $userData = json_decode($cached, true);
        $token = rand(1000, 9999);

        // Update the token in the cache
        Cache::put($cacheKey, json_encode(array_merge($userData, ['token' => $token])), now()->addMinutes(10));

        // Send email with new token
        Mail::to($request->email)->queue(new AuthorVerificationToken($token));

        return $this->success(
            ['email' => $request->email],
            'New verification token sent to your email.',
            202
        );
    }

    public function verifyAuthorEmail(Request $request)
    {
        // dd($request->all());
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'token' => 'required|digits:4',
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validator->errors()
            );
        }

        // Check if email already exists as author
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser) {
            return $this->error(
                'Email already exists.',
                409,
                null
            );
        }

        $cacheKey = "author_register_{$request->email}";
        $cached = Cache::get($cacheKey);

        if (! $cached) {
            return $this->error(
                'Verification expired or not found.',
                410,
                null
            );
        }

        $userData = json_decode($cached, true);

        if ($userData['token'] != $request->token) {
            return $this->error(
                'Invalid verification token.',
                401,
                null
            );
        }

        try {
            $user = new User;
            $user->fill([
                'email' => $userData['email'],
                'password' => $userData['password'],
                'default_login' => $userData['default_login'],
                'account_type' => $userData['account_type'],
                'status' => 'unverified',
            ]);
            $user->save();

            $role = Role::where('name', 'user')->first();
            if ($role) {
                $user->assignRole($role);
            }

            $user->refresh();

            Cache::forget($cacheKey);

            Mail::to($user->email)->queue(new WelcomeEmail($user->name || 'NO NAME', $user->account_type));

            // Generate Authentication Token
            $token = $user->createToken('auth_token')->plainTextToken;

            return $this->success([
                'user_id' => $user->id,
                'email' => $user->email,
                'role' => $user->getRoleNames()->first(),
                'token' => $token,
                'account_type' => $user->account_type,
            ], 'Author account created successfully, kindly proceed to verification.', 201);
        } catch (\Exception $e) {
            return $this->error(
                'An error occurred while creating the author account.',
                500,
                config('app.debug') ? $e->getMessage() : 'Error creating author account.',
                $e
            );
        }
    }

    public function profile(Request $request)
    {
        // dd($request);
        $user = $request->user()->load('bookmarks', 'kycInfo', 'purchasedBooks'); // ->only(['id', 'name', 'email', 'status', 'account_type', 'last_login_at']);
        // dd($user);

        return $this->success(new UserResource($user),
            'Profile retrieved successfully!',
            200);
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = $request->user();
            // dd($request->all());

            // Common validation rules
            $rules = [
                'name' => 'nullable|string|max:255',
                'profile_info.username' => 'nullable|string|max:255|unique:users,username,'.$user->id,
                'profile_info.bio' => 'nullable|string|max:1000',
                'profile_info.pronouns' => 'nullable|string|max:50',
                // 'profile_picture' => 'nullable|array',
                'profile_picture' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
                // 'profile_picture.public_id' => 'nullable|string|max:255',
            ];

            if ($user->account_type === 'author') {
                // Add author-specific required fields
                $rules = array_merge($rules, [
                    'socials' => 'nullable|array|min:1',
                    'socials.*.platform' => 'nullable|string|max:50',
                    'socials.*.url' => 'nullable|url|max:255',
                    'preferences.genres' => 'nullable|array|min:1',
                    'preferences.genres.*' => 'nullable|string|max:50',
                    // 'first_name' => 'required|string|max:255',
                ]);
            } /*else {
                return $this->error('Invalid account type.', 400, null);
            }*/

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // Handle profile picture upload if present
            if ($request->hasFile('profile_picture')) {
                $file = $request->file('profile_picture');
                // Store image (e.g., in 'profile_pictures' disk), generate public_id and url
                $upload = $this->mediaUploader()->upload($file, 'user_avatar', $user);

                // dd($upload);

                $profilePicture = [
                    'public_id' => $upload['url'],
                    'public_url' => $upload['id'],
                ];
            } else {
                $profilePicture = $request->input('profile_picture', []);
            }

            // Update user fields
            // $user->profile_info = $request->input('profile_info', $user->profile_info);
            $user->name = $request->input('name', $user->name);
            $user->username = $request->input('profile_info.username', $user->username);
            $user->bio = $request->input('profile_info.bio', $user->bio);
            $user->pronouns = $request->input('profile_info.pronouns', $user->pronouns);
            $user->profile_picture = $profilePicture;

            if ($user->account_type === 'author') {
                // $user->socials = $request->input('socials', $user->socials ?? []); // TODO - <!-- uncomment this when ready -->
                $existingPreferences = $user->preferences ?? [];
                $newPreferences = $request->input('preferences', []);
                $user->preferences = array_merge($existingPreferences, $newPreferences);

                // Merge settings if already exists, otherwise set new
                $existingSettings = $user->settings ?? [];
                $newSettings = $request->input('settings', []);
                $user->settings = array_merge($existingSettings, $newSettings);
            }

            $user->save();
            if ($request->hasFile('profile_picture')) {
                MediaUpload::where('id', $upload['id'])->update([
                    'mediable_type' => 'user',
                    'mediable_id' => $user->id,
                ]);
            }

            return $this->success(
                new UserResource($user),
                'Profile updated successfully!',
                200
            );
        } catch (\Throwable $th) {
            // dd($th);
            return $this->error(
                'An error occurred while updating your profile.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while updating your profile.',
                $th
            );
        }
    }

    /**
     * Update settings for profile
     */
    public function updatePreferences(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'interests' => 'array',
                'sort_by' => 'required|in:popularity,recent',
                'items_per_page' => 'integer|min:1|max:50',
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // Update the object to jsonb of preferences in the db
            $user = $request->user();
            $user->preferences = array_merge($user->preferences ?? [], $request->all());
            $user->save();

            return $this->success(
                $user->preferences,
                'Preferences updated successfully!',
                200
            );
        } catch (\Throwable $th) {
            // throw $th;
            // dd($th);
            return $this->error(
                'An error occurred while updating your preferences.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while updating your preferences.',
                $th
            );
        }
    }

    /**
     * Update settings for profile
     */
    public function updateSettings(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'theme' => ['required', 'string', 'in:light,dark'],
                'notifications' => ['required', 'array', function ($attribute, $value, $fail) {
                    $allowedKeys = ['email', 'sms'];
                    foreach ($value as $key => $val) {
                        if (! in_array($key, $allowedKeys)) {
                            return $fail("The {$attribute} field contains an invalid key: {$key}.");
                        }
                        if (! is_bool($val)) {
                            return $fail("The value for {$attribute}.{$key} must be true or false.");
                        }
                    }
                }],
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // Update the object to jsonb of settings in the db
            $user = $request->user();
            $user->settings = array_merge($user->settings ?? [], $request->all());
            $user->save();

            return $this->success(
                $user->settings,
                'Settings updated successfully!',
                200
            );
        } catch (\Throwable $th) {
            // throw $th;
            // dd($th);
            return $this->error(
                'An error occurred while updating your settings.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while updating your settings.',
                $th
            );
        }
    }

    /**
     * Change password logic for logged in users. and email notification for password change
     */
    public function changePassword(Request $request)
    {
        try {
            // Validate request with a custom rule for current password using Hash::check
            $validator = Validator::make($request->all(), [
                'current_password' => [
                    'required',
                    function ($attribute, $value, $fail) use ($request) {
                        if (! Hash::check($value, $request->user()->password)) {
                            $fail('The current password is incorrect.');
                        }
                    },
                ],
                'new_password' => [
                    'required',
                    'string',
                    'min:8',
                    // Ensure at least one uppercase, one lowercase, one number, and one special character
                    'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).+$/',
                ],
                'confirm_new_password' => ['required', 'same:new_password'],
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            $user = $request->user();
            $user->password = Hash::make($request->new_password);
            $user->save();

            $this->sendPasswordChangeEmail($user);

            return $this->success(
                null,
                'Password changed successfully!',
                200
            );
        } catch (\Throwable $th) {
            return $this->error(
                'An error occurred while changing your password.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while changing your password.',
                $th
            );
        }
    }

    private function sendPasswordChangeEmail($user)
    {
        $details = [
            'subject' => 'Password Change Notification',
            'name' => $user->name,
            'body' => "Hello {$user->name},\n\nYour password has been successfully changed. If you did not authorize this change, please contact support immediately.",
        ];

        Mail::send('emails.password_change', $details, function ($message) use ($user, $details) {
            $message->to($user->email)
                ->subject($details['subject']);
        });
    }

    // Add a new payment method for the user reader
    public function addCard(Request $request)
    {
        try {
            $user = $request->user();

            // Validate request token received from frontend for payment method id
            $validator = Validator::make($request->all(), [
                'payment_method_id' => 'required|string',
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // Ensure the user has a Stripe customer ID
            if (! $user->kyc_customer_id) {
                return $this->error(
                    'Stripe customer ID not found.',
                    400,
                    null
                );
            }

            $stripeResponse = $this->stripe->addCard($request->all(), $user);

            // Handle the mixed return type
            $responsePayload = []; // Data to return to the frontend

            if ($stripeResponse instanceof JsonResponse) {
                // Error returned from Stripe service
                $responseData = $stripeResponse->getData(true);
                $errorMessage = $responseData['error'] ?? 'Unknown error from payment service.';

                return $this->error(
                    'Failed to add payment method.',
                    $stripeResponse->getStatusCode(),
                    config('app.debug') ? $errorMessage : null
                );
            } elseif ($stripeResponse instanceof \App\Models\PaymentMethod) {
                // Payment method created successfully
                $responsePayload = $stripeResponse;
            } else {
                // Unexpected return type
                return $this->error(
                    'An internal error occurred due to an unexpected service response.',
                    500,
                    config('app.debug') ? 'Stripe service returned an unhandled type.' : null
                );
            }

            // Return success response with the prepared payload
            return $this->success(
                $responsePayload,
                'Payment method added successfully!',
                200
            );
        } catch (\Throwable $th) {
            return $this->error(
                'An error occurred while adding the payment method.',
                500,
                config('app.debug') ? $th->getMessage() : null, // Pass $th here if your error method handles debug details
                $th // Pass the actual exception object
            );
        }
    }

    // Add a new payment method for the user author add bank account
    public function addBankAccount(Request $request)
    {
        try {
            $user = $request->user();

            // Validate request token received from frontend for payment method id
            $validator = Validator::make($request->all(), [
                'account_number' => 'required|string',
                'routing_number' => 'nullable|string',
                'account_holder_name' => 'required|string',
                'account_holder_type' => 'required|string|in:individual,company',
                'country' => 'required|string|size:2|in:NG,CA', // 2-letter ISO country code
                'currency' => 'required|string|size:3', // 3-letter ISO currency code
                'sort_code' => 'nullable|string', // Only required for Nigeria
                // Add any other required fields for bank account
            ]);

            if ($validator->fails()) {
                return $this->error(
                    'Validation failed',
                    400,
                    $validator->errors()
                );
            }

            // For Nigeria (NG), we need to use the correct bank format
            if ($request->input('country') === 'NG') {
                $bankAccountData['sort_code'] = $request->input('sort_code');  // Nigeria uses sort code (Bank Code)
                $bankAccountData['account_holder_name'] = $user->name;
                $bankAccountData['currency'] = 'usd'; // USD or the local currency for Stripe payout
            }

            // For Canada (CA), we need to include the correct routing number
            if ($request->input('country') === 'CA') {
                $bankAccountData['routing_number'] = $request->input('routing_number');  // Canada uses routing number
                $bankAccountData['account_holder_name'] = $user->name;
                $bankAccountData['currency'] = 'cad'; // Canadian dollars
            }

            // Only allow bank account for author
            if ($user->account_type !== 'author') {
                return $this->error(
                    'Only authors can add a bank account.',
                    403,
                    null
                );
            }

            // Ensure the user has a Stripe customer ID
            if (! $user->kyc_account_id) {
                return $this->error(
                    'Stripe account ID not found.',
                    400,
                    null
                );
            }

            $bankAccount = $this->stripe->addBankAccount($request->all(), $user);

            if (isset($bankAccount->getData()->error)) {
                return $this->error(
                    'Failed to add bank account.',
                    400,
                    $bankAccount->getData()->error
                );
            }

            return $this->success(
                $bankAccount,
                'Bank account added successfully!',
                200
            );
        } catch (\Throwable $th) {
            // throw $th;
            return $this->error(
                'An error occurred while adding the bank account.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while adding the bank account.',
                $th
            );
        }
    }

    // Get a list of payment methods for the user
    public function listPaymentMethods(Request $request)
    {
        try {
            $user = $request->user();

            return $this->stripe->listPaymentMethods($user);

            // dd($paymentMethods);
            // convert to json
            // $paymentMethods = json_encode($paymentMethods);

            // dd(isset($paymentMethods['error']));

            // $paymentMethodsData = (array) $paymentMethods->getData();
            // $is_error = isset($paymentMethodsData['error']);
            // if ($is_error) {
            //     return response()->json([
            //         'data' => null,
            //         'code' => 400,
            //         'message' => $paymentMethods['error'],
            //     ], 400);
            // }

            // return response()->json([
            //     'data' => $paymentMethods,
            //     'code' => 200,
            //     'message' => 'Payment methods retrieved successfully!',
            // ], 200);
        } catch (\Throwable $th) {
            // throw $th;
            // dd($th);
            return $this->error(
                'An error occurred while retrieving payment methods.',
                500,
                config('app.debug') ? $th->getMessage() : 'An error occurred while retrieving payment methods.',
                $th
            );
        }
    }

    // ================================================================================================================================================
    //                                                 USER MANAGEMENT Controllers
    // ================================================================================================================================================
    public function allUsers(Request $request)
    {
        try {
            // Validate query parameters
            $validator = Validator::make($request->all(), [
                'per_page' => 'sometimes|integer|min:1|max:100',
                'page' => 'sometimes|integer|min:1',
                'search' => 'sometimes|string|max:255',
                'account_type' => 'sometimes|string|in:reader,author,superadmin',
                'status' => 'sometimes|string|in:active,inactive,unverified,suspended',
                'role' => 'sometimes|string|exists:roles,name',
                'sort_by' => 'sometimes|string|in:id,name,email,created_at,updated_at',
                'sort_order' => 'sometimes|string|in:asc,desc',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed.', 400, $validator->errors());
                // return response()->json([
                //     'data' => null,
                //     'code' => 400,
                //     'message' => $validator->errors(),
                // ], 400);
            }

            // Default pagination values
            $perPage = $request->input('per_page', 20);
            $page = $request->input('page', 1);

            // Search and filter parameters
            $search = $request->input('search');
            $accountType = $request->input('account_type'/* , 'author' */);
            $status = $request->input('status'/* , 'verified' */);
            $role = $request->input('role');

            // Build query
            $query = User::with([
                'paymentMethods',
                'professionalProfile',
                'roles',
                'kycInfo',
                'purchasedBooks',
                'bookmarks',
            ]);

            // Search by email, name, or username
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('email', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('username', 'like', "%{$search}%");
                });
            }

            // Filter by account type
            if ($accountType) {
                $query->where('account_type', $accountType);
            }

            // Filter by status
            if ($status) {
                $query->where('status', $status);
            }

            // Filter by role
            if ($role) {
                $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('name', $role);
                });
            }

            // Sorting
            $sortBy = $request->input('sort_by', 'created_at');
            $sortOrder = $request->input('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Paginate
            $users = $query->paginate($perPage, ['*'], 'page', $page);

            return $this->success($users, 'Users retrieved successfully.', 200);
        } catch (\Throwable $e) {
            return $this->error(config('app.debug') ? $e->getMessage() : 'An error occurred while retrieving users.', 500);
            // return response()->json([
            //     'data' => null,
            //     'code' => 500,
            //     'message' => env('APP_DEBUG') ? $e->getMessage() : 'An error occurred while retrieving users.',
            // ], 500);
        }
    }

    public function singleUserById(Request $request, $id)
    {
        // dd('HERE>>>>', $request, $id);
        // Validate the ID parameter
        $validator = Validator::make(['id' => $id], [
            'id' => 'required|integer|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->error(
                'Validation failed',
                400,
                $validator->errors()
            );
        }

        try {
            $user = User::with([
                'paymentMethods',
                'professionalProfile',
                'roles',
                'kycInfo',
                'purchasedBooks',
                'bookmarks',
            ])->find($id);

            if (! $user) {
                return $this->error(
                    'User not found.',
                    404,
                    null
                );
            }

            return $this->success(
                new UserResource($user),
                'User retrieved successfully.',
                200
            );
        } catch (\Throwable $e) {
            return $this->error(
                'An error occurred while retrieving the user.',
                500,
                config('app.debug') ? $e->getMessage() : 'An error occurred while retrieving the user.'
            );
        }
    }

    /**
     * Admin action to approve, decline, verify, suspend, etc. a user account.
     * Route: /user/{action}/{user_id}
     */
    public function adminAproveOrDeclineActionOnUser(Request $request, $action, $user_id)
    {
        // dd($action, $user_id, $request);
        // Allowed status actions
        $allowedActions = ['active', 'suspended', 'unverified', 'verified', 'pending', 'banned', 'rejected'];

        // Validate action
        if (! in_array($action, $allowedActions)) {
            return $this->error('Invalid action. Allowed actions: '.implode(', ', $allowedActions), 400);
        }

        // Validate user
        $user = User::find($user_id);
        if (! $user) {
            $this->error('User not found.', 404);
        }

        // dd($user);

        // Special logic for verification
        if ($action === 'verified') {
            // dd($user);
            // Only authors can be verified
            if ($user->account_type !== 'author') {
                return $this->error('Only authors can be verified.', 400);
            }

            // // Ensure kyc status is verified
            // if($user->kyc_status !== 'verified') { //NOTE - Uncomment to enforce KYC verification
            //     return $this->error('Users kyc status is not verified', 400);
            // }

            // If already verified
            if ($user->status === 'verified') {
                return $this->error('User is already verified.', 409);
            }
        }

        // Update status
        $user->status = $action;
        $user->save();

        return $this->success(
            [
                'user_id' => $user->id,
                'status' => $user->status,
            ],
            "User status updated to '{$action}' successfully.",
            200
        );
    }
}
