<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\LinkedAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class LinkedAccountController extends Controller
{
    /**
     * POST /user/linked-account
     *
     * Creates a second account (opposite type) and links it to the authenticated user.
     * Requires the user's current password to verify identity.
     */
    public function create(Request $request)
    {
        try {
            $user = $request->user();

            $oppositeType = $user->account_type === 'author' ? 'reader' : 'author';

            $validator = Validator::make($request->all(), [
                'account_type' => 'required|string|in:reader,author',
                'password'     => 'required|string',
            ], [
                'account_type.required' => 'Please specify the account type to add.',
                'account_type.in'       => 'Account type must be either "reader" or "author".',
                'password.required'     => 'Please enter your current password to confirm.',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed.', 400, $validator->errors());
            }

            if ($request->account_type === $user->account_type) {
                return $this->error('You already have a ' . $user->account_type . ' account.', 409);
            }

            if ($request->account_type !== $oppositeType) {
                return $this->error('You can only add a ' . $oppositeType . ' account to this profile.', 400);
            }

            if (! Hash::check($request->password, $user->password)) {
                return $this->error('Incorrect password. Please try again.', 401);
            }

            // Already linked
            $existingLink = LinkedAccount::where('user_id', $user->id)->first();
            if ($existingLink) {
                return $this->error('You already have a linked ' . $oppositeType . ' account.', 409);
            }

            DB::beginTransaction();

            // Reuse an existing account with the same email + requested type, or create one
            $linked = User::where('email', $user->email)
                ->where('account_type', $request->account_type)
                ->first();

            if (! $linked) {
                $linked = new User;
                $linked->email         = $user->email;
                $linked->account_type  = $request->account_type;
                $linked->default_login = 'email';
                $linked->status        = $request->account_type === 'reader' ? 'active' : 'unverified';
                $linked->preferences   = [];
                // Save first (without password) to obtain an auto-increment ID
                $linked->save();

                // Copy the already-hashed password via a raw query to bypass the 'hashed' cast,
                // which would otherwise double-hash the value.
                DB::table('users')
                    ->where('id', $linked->id)
                    ->update(['password' => $user->getAttributes()['password']]);

                $role = Role::where('name', 'user')->first();
                if ($role) {
                    $linked->assignRole($role);
                }

                $linked->refresh();
            }

            // Bidirectional link so both users can find each other
            LinkedAccount::create(['user_id' => $user->id,   'linked_user_id' => $linked->id]);
            LinkedAccount::create(['user_id' => $linked->id, 'linked_user_id' => $user->id]);

            $token = $linked->createToken('auth_token')->plainTextToken;

            DB::commit();

            return $this->success([
                'user_id'      => $linked->id,
                'email'        => $linked->email,
                'account_type' => $linked->account_type,
                'status'       => $linked->status,
                'token'        => $token,
            ], ucfirst($request->account_type) . ' account linked successfully.', 201);
        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->error('An error occurred while linking the account.', 500, config('app.debug') ? $th->getMessage() : null, $th);
        }
    }

    /**
     * GET /user/linked-account
     *
     * Returns the linked account's info and a fresh token for it.
     * Returns null data if no linked account exists.
     */
    public function show(Request $request)
    {
        try {
            $user = $request->user();

            $link = LinkedAccount::where('user_id', $user->id)
                ->with('linkedUser')
                ->first();

            if (! $link || ! $link->linkedUser) {
                return $this->success(null, 'No linked account found.', 200);
            }

            $linked = $link->linkedUser;
            $token  = $linked->createToken('auth_token')->plainTextToken;

            return $this->success([
                'user_id'      => $linked->id,
                'email'        => $linked->email,
                'account_type' => $linked->account_type,
                'status'       => $linked->status,
                'username'     => $linked->username,
                'token'        => $token,
            ], 'Linked account retrieved.', 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while retrieving the linked account.', 500, config('app.debug') ? $th->getMessage() : null, $th);
        }
    }

    /**
     * DELETE /user/linked-account
     *
     * Removes the bidirectional link between the two accounts.
     * Does NOT delete either user account.
     */
    public function destroy(Request $request)
    {
        try {
            $user = $request->user();

            $link = LinkedAccount::where('user_id', $user->id)->first();

            if (! $link) {
                return $this->error('No linked account found.', 404);
            }

            $linkedUserId = $link->linked_user_id;

            // Remove both directions
            LinkedAccount::where(function ($q) use ($user, $linkedUserId) {
                $q->where('user_id', $user->id)->where('linked_user_id', $linkedUserId);
            })->orWhere(function ($q) use ($user, $linkedUserId) {
                $q->where('user_id', $linkedUserId)->where('linked_user_id', $user->id);
            })->delete();

            return $this->success(null, 'Account unlinked successfully.', 200);
        } catch (\Throwable $th) {
            return $this->error('An error occurred while unlinking the account.', 500, config('app.debug') ? $th->getMessage() : null, $th);
        }
    }
}
