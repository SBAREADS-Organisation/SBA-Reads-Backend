<?php

namespace App\Http\Controllers;

use App\Mail\Generic\AccountDeletionNotice;
use App\Models\AccountDeletionRequest;
use App\Models\User;
use App\Services\User\AccountDeletionService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AccountDeletionController extends Controller
{
    private const CODE_MINUTES = 10;
    private const MAX_ATTEMPTS = 5;

    /** Public: email a verification code. The response never reveals whether the account exists. */
    public function sendCode(Request $request)
    {
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return $this->error('Please enter a valid email address.', 400, $validator->errors());
        }

        $accounts = User::where('email', $request->email)->orderBy('id')->get();
        $user = $accounts->first();

        if ($user && ! $accounts->contains(fn (User $account) => $this->isStaff($account))) {
            $code = (string) random_int(100000, 999999);
            $expiresAt = now()->addMinutes(self::CODE_MINUTES);

            Cache::put($this->cacheKey($request->email), [
                'hash' => $this->hash($code),
                'attempts' => 0,
                'expires_at' => $expiresAt->getTimestamp(),
            ], $expiresAt);

            $this->sendMail($user->email, new AccountDeletionNotice(
                'Confirm your account deletion request',
                [
                    'Someone asked to delete the SBA Reads account for this email. Enter this code to confirm it. It expires in ' . self::CODE_MINUTES . ' minutes.',
                    'If this wasn\'t you, ignore this email and your account will stay as it is.',
                ],
                $code,
            ));
        }

        return $this->success(null, 'If an account exists for that email, we have sent it a code.');
    }

    /** Public: verify the code and record the request for staff to process. */
    public function confirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'otp' => 'required|digits:6',
            'reason' => 'nullable|string|max:2000',
            'acknowledged' => 'accepted',
        ], [
            'otp.digits' => 'The code must be exactly 6 digits.',
            'acknowledged.accepted' => 'Please confirm that you understand deletion is permanent.',
        ]);
        if ($validator->fails()) {
            return $this->error('Please check your details and try again.', 422, $validator->errors());
        }

        $key = $this->cacheKey($request->email);
        $record = Cache::get($key);

        if (! $record || $record['attempts'] >= self::MAX_ATTEMPTS) {
            Cache::forget($key);
            return $this->error('Invalid or expired code', 400);
        }

        if (! hash_equals($record['hash'], $this->hash((string) $request->otp))) {
            $record['attempts']++;
            Cache::put($key, $record, Carbon::createFromTimestamp($record['expires_at']));
            return $this->error('Invalid or expired code', 400);
        }

        Cache::forget($key);

        // One email can hold a reader and an author account. Record the author one when both
        // exist, so staff handle it by hand rather than deleting the reader one automatically.
        $accounts = User::where('email', $request->email)->orderBy('id')->get();
        $user = $accounts->first(fn (User $account) => $account->account_type !== 'reader') ?? $accounts->first();
        if (! $user) {
            return $this->error('Invalid or expired code', 400);
        }

        $existing = AccountDeletionRequest::where('user_id', $user->id)
            ->where('status', AccountDeletionRequest::STATUS_PENDING)
            ->first();
        if ($existing) {
            return $this->success(null, 'Your deletion request is already with our team.');
        }

        $deletionRequest = AccountDeletionRequest::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'account_type' => $user->account_type,
            'reason' => $request->input('reason'),
            'admin_notes' => $accounts->count() > 1 ? 'This email has more than one account: ' . $accounts->pluck('account_type')->join(', ') . '.' : null,
        ]);

        $this->sendMail($user->email, new AccountDeletionNotice('We received your account deletion request', [
            'Our team will process your request within 7–14 business days. Your account stays active until then.',
            'If you change your mind, reply to this email or contact support@sbareads.com before it is processed.',
        ]));
        $this->sendMail(config('services.account_deletion.notify_email'), new AccountDeletionNotice('New account deletion request', [
            'Request #' . $deletionRequest->id . ' from ' . $user->email . ' (' . $user->account_type . ').',
            'Reason: ' . ($request->input('reason') ?: 'Not given'),
        ]));

        return $this->success(null, 'Your deletion request has been received.', 201);
    }

    /** Staff: list requests, newest first. */
    public function index(Request $request)
    {
        $requests = AccountDeletionRequest::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(min((int) $request->input('items_per_page', 20), 100));

        return $this->success($requests, 'Deletion requests retrieved successfully.');
    }

    /** Staff: permanently delete a reader account. Author accounts are refused; see AccountDeletionService. */
    public function complete(Request $request, int $id, AccountDeletionService $service)
    {
        $deletionRequest = AccountDeletionRequest::find($id);
        if (! $deletionRequest) {
            return $this->error('Deletion request not found.', 404);
        }
        if ($deletionRequest->status !== AccountDeletionRequest::STATUS_PENDING) {
            return $this->error('This request has already been processed.', 409);
        }

        $user = User::find($deletionRequest->user_id);

        if ($user && $user->account_type !== 'reader') {
            return $this->error(
                'This is not a reader account, so it can\'t be deleted automatically. Take its books off sale and pay any pending royalties first, then mark the request as handled.',
                409
            );
        }

        try {
            if ($user) {
                $service->deleteReader($user);
            }
        } catch (\Throwable $e) {
            return $this->error('Failed to delete the account.', 500, null, $e);
        }

        $this->markProcessed($deletionRequest, $request, AccountDeletionRequest::STATUS_COMPLETED);

        $this->sendMail($deletionRequest->email, new AccountDeletionNotice('Your account has been deleted', [
            'Your SBA Reads account has been permanently deleted, as you requested.',
            'Payment history may be retained for legal and tax obligations.',
        ]));

        return $this->success($deletionRequest, 'Account deleted.');
    }

    /** Staff: close a request without automatic deletion, such as after handling an author account by hand. */
    public function update(Request $request, int $id)
    {
        $deletionRequest = AccountDeletionRequest::find($id);
        if (! $deletionRequest) {
            return $this->error('Deletion request not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:' . AccountDeletionRequest::STATUS_COMPLETED . ',' . AccountDeletionRequest::STATUS_REJECTED,
            'admin_notes' => 'nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->error('Please check your changes and try again.', 422, $validator->errors());
        }
        if ($deletionRequest->status !== AccountDeletionRequest::STATUS_PENDING) {
            return $this->error('This request has already been processed.', 409);
        }

        $deletionRequest->admin_notes = $request->input('admin_notes');
        $this->markProcessed($deletionRequest, $request, $request->input('status'));

        return $this->success($deletionRequest, 'Request updated.');
    }

    // Staff accounts are managed by superadmins, not through this public form.
    private function isStaff(User $user): bool
    {
        return in_array($user->account_type, ['superadmin', 'support', 'manager'], true)
            || $user->hasAnyRole(['admin', 'superadmin', 'manager']);
    }

    private function markProcessed(AccountDeletionRequest $deletionRequest, Request $request, string $status): void
    {
        $deletionRequest->status = $status;
        $deletionRequest->processed_by = $request->user()->id;
        $deletionRequest->processed_at = now();
        $deletionRequest->save();
    }

    private function sendMail(string $to, Mailable $mail): void
    {
        try {
            Mail::to($to)->send($mail);
        } catch (\Throwable $e) {
            Log::error('Account deletion email failed', ['error' => $e->getMessage()]);
        }
    }

    private function hash(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function cacheKey(string $email): string
    {
        return 'account_deletion:code:' . $this->hash(strtolower(trim($email)));
    }
}
