<?php

namespace App\Services\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountDeletionService
{
    /**
     * Permanently removes a reader account. Orders, payments and transactions
     * stay for financial records; only personal data and the user row go.
     *
     * Author accounts are deliberately not handled here: books must come off
     * sale without removing them from buyers' libraries, and pending royalties
     * must be paid first, so they need staff to handle them individually.
     */
    public function deleteReader(User $user): void
    {
        if ($user->account_type !== 'reader') {
            throw new \InvalidArgumentException('Only reader accounts can be deleted automatically.');
        }

        DB::transaction(function () use ($user) {
            $id = $user->id;

            DB::table('personal_access_tokens')->where('tokenable_id', $id)->delete();
            DB::table('social_accounts')->where('user_id', $id)->delete();
            DB::table('linked_accounts')->where('user_id', $id)->delete();

            DB::table('book_user')->where('user_id', $id)->delete();
            DB::table('book_user_bookmarks')->where('user_id', $id)->delete();
            DB::table('reading_progress')->where('user_id', $id)->delete();

            DB::table('book_reviews')->where('user_id', $id)->delete();
            DB::table('notifications')->where('notifiable_id', $id)->delete();

            DB::table('model_has_roles')->where('model_id', $id)->where('model_type', get_class($user))->delete();
            DB::table('model_has_permissions')->where('model_id', $id)->where('model_type', get_class($user))->delete();

            DB::table('media_uploads')->where('mediable_type', 'user')->where('mediable_id', $id)->delete();

            DB::table('k_y_c_verifications')->where('user_id', $id)->delete();
            DB::table('user_kyc_infos')->where('user_id', $id)->delete();

            $user->delete();
        });
    }
}
