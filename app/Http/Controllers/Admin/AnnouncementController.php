<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
use App\Models\Book;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class AnnouncementController extends Controller
{
    /**
     * Send an email blast to users.
     *
     * Target options:
     *   all     — every registered user
     *   readers — users with account_type = 'reader'
     *   authors — users with account_type = 'author'
     */
    public function emailBlast(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:5000',
            'target'  => 'required|in:all,readers,authors',
        ]);

        if ($validator->fails()) {
            return $this->error('Validation failed', 400, $validator->errors());
        }

        $query = User::whereNotNull('email');

        if ($request->input('target') !== 'all') {
            $query->where('account_type', $request->input('target') === 'readers' ? 'reader' : 'author');
        }

        $total   = $query->count();
        $queued  = 0;
        $subject = $request->input('subject');
        $message = $request->input('message');

        $query->select(['id', 'email', 'name', 'first_name'])->chunk(100, function ($users) use ($subject, $message, &$queued) {
            foreach ($users as $user) {
                try {
                    $greeting = ($user->first_name && strtoupper(trim($user->first_name)) !== 'NO NAME')
                        ? $user->first_name
                        : (($user->name && strtoupper(trim($user->name)) !== 'NO NAME') ? $user->name : null);

                    $body = $greeting
                        ? "Hi {$greeting},\n\n{$message}"
                        : $message;

                    Mail::to($user->email)->queue(new GenericAppNotification($subject, $body));
                    $queued++;
                } catch (\Throwable $e) {
                    Log::warning('AnnouncementController email failed', [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Admin email blast sent', [
            'admin_id' => $request->user()->id,
            'target'   => $request->input('target'),
            'total'    => $total,
            'queued'   => $queued,
            'subject'  => $subject,
        ]);

        return $this->success(
            ['total_recipients' => $total, 'queued' => $queued],
            "Email blast queued for {$queued} users.",
            200
        );
    }

    /**
     * Notify authors whose books have no cover image to upload a proper front cover.
     */
    public function notifyBadCover(Request $request): JsonResponse
    {
        $authorIds = Book::where('account_type_owner', 'author')
            ->whereNull('cover_image')
            ->orWhere('cover_image', '')
            ->distinct()
            ->pluck('author_id')
            ->filter()
            ->unique()
            ->toArray();

        if (empty($authorIds)) {
            return $this->success(['total_recipients' => 0, 'queued' => 0], 'No authors found with missing cover images.');
        }

        $queued = 0;
        User::whereIn('id', $authorIds)
            ->whereNotNull('email')
            ->select(['id', 'email', 'name', 'first_name'])
            ->chunk(100, function ($authors) use (&$queued) {
                foreach ($authors as $author) {
                    try {
                        $name = ($author->first_name && strtoupper(trim($author->first_name)) !== 'NO NAME')
                            ? $author->first_name
                            : ($author->name ?? 'Author');

                        Mail::to($author->email)->queue(new GenericAppNotification(
                            'SBA Reads — Please Update Your Book Cover',
                            "Hi {$name},\n\n"
                            . "We noticed that one or more of your books on SBA Reads is missing a cover image, or the cover does not meet our display standards.\n\n"
                            . "A clear, high-quality front cover helps readers find and trust your work. Please log in to the SBA Reads Author portal and upload a proper front cover image for each book.\n\n"
                            . "Note: Upload the front cover only — not a full spread or back cover.\n\n"
                            . "If you need help, contact us at support@sbareads.com.\n\n"
                            . "— The SBA Reads Team"
                        ));
                        $queued++;
                    } catch (\Throwable $e) {
                        Log::warning('notifyBadCover email failed', ['author_id' => $author->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        Log::info('Admin notifyBadCover sent', ['admin_id' => $request->user()->id, 'queued' => $queued]);

        return $this->success(['total_recipients' => count($authorIds), 'queued' => $queued], "Cover reminder queued for {$queued} authors.");
    }

    /**
     * Notify authors who have not submitted a government ID for KYC to do so.
     */
    public function notifyMissingId(Request $request): JsonResponse
    {
        $authorIds = User::where('account_type', 'author')
            ->whereNotNull('email')
            ->where(function ($q) {
                $q->whereNull('kyc_status')
                  ->orWhereIn('kyc_status', ['unverified', 'document-required', 'pending_manual']);
            })
            ->whereDoesntHave('kycInfo', function ($q) {
                $q->whereNotNull('document_type');
            })
            ->pluck('id')
            ->toArray();

        if (empty($authorIds)) {
            return $this->success(['total_recipients' => 0, 'queued' => 0], 'No authors found with missing ID.');
        }

        $queued = 0;
        User::whereIn('id', $authorIds)
            ->select(['id', 'email', 'name', 'first_name'])
            ->chunk(100, function ($authors) use (&$queued) {
                foreach ($authors as $author) {
                    try {
                        $name = ($author->first_name && strtoupper(trim($author->first_name)) !== 'NO NAME')
                            ? $author->first_name
                            : ($author->name ?? 'Author');

                        Mail::to($author->email)->queue(new GenericAppNotification(
                            'SBA Reads — ID Verification Required',
                            "Hi {$name},\n\n"
                            . "To start receiving payouts from your book sales on SBA Reads, you need to verify your identity by submitting a valid government-issued ID.\n\n"
                            . "This is required to comply with financial regulations and to protect your earnings.\n\n"
                            . "Steps:\n"
                            . "1. Open the SBA Reads Author app\n"
                            . "2. Go to Profile → KYC Verification\n"
                            . "3. Upload a clear photo of your government-issued ID (National ID, Driver's Licence, or International Passport)\n\n"
                            . "Once submitted, our team will review and verify your identity within 24–48 hours.\n\n"
                            . "If you need help, contact us at support@sbareads.com.\n\n"
                            . "— The SBA Reads Team"
                        ));
                        $queued++;
                    } catch (\Throwable $e) {
                        Log::warning('notifyMissingId email failed', ['author_id' => $author->id, 'error' => $e->getMessage()]);
                    }
                }
            });

        Log::info('Admin notifyMissingId sent', ['admin_id' => $request->user()->id, 'queued' => $queued]);

        return $this->success(['total_recipients' => count($authorIds), 'queued' => $queued], "ID reminder queued for {$queued} authors.");
    }
}
