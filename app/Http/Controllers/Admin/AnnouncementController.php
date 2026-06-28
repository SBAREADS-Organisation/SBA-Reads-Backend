<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\Generic\GenericAppNotification;
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
}
