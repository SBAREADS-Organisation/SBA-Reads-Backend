<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBookAudioJob;
use App\Jobs\VoiceCloningJob;
use App\Models\Book;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AudioController extends Controller
{
    use ApiResponse;

    /**
     * Save voice sample to local disk and dispatch async cloning job.
     * POST /user/voice-sample
     */
    public function uploadVoiceSample(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voice_sample' => 'required|file|mimes:mp3,m4a,wav,mp4,aac,ogg|max:25600',
        ], [
            'voice_sample.required' => 'Please provide a voice sample recording.',
            'voice_sample.mimes'    => 'Voice sample must be an audio file (mp3, m4a, wav, aac, ogg).',
            'voice_sample.max'      => 'Voice sample must be under 25 MB.',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $user = $request->user();

        try {
            $file = $request->file('voice_sample');
            $ext  = $file->getClientOriginalExtension() ?: 'm4a';

            if ($file->getSize() < 25600) {
                return $this->error('Voice sample is too small. Please upload at least 30 seconds of clean audio.', 422);
            }

            // Save to local disk immediately (fast) — Cloudinary upload + ElevenLabs cloning run in the background job
            $storagePath = $file->storeAs(
                'voice-samples',
                "user_{$user->id}_".time().".$ext",
                'local'
            );

            if (! $storagePath) {
                return $this->error('Failed to save voice sample. Please try again.', 500);
            }

            $user->update(['voice_status' => 'processing']);

            $voiceName = ($user->name ?? 'author').'-'.$user->id;
            VoiceCloningJob::dispatch($user->id, Storage::disk('local')->path($storagePath), $voiceName)
                ->onQueue('voice');

            return $this->success([
                'voice_status' => 'processing',
                'has_voice'    => false,
            ], 'Voice sample received. Cloning is in progress — you will be notified when it is ready.');

        } catch (\Throwable $e) {
            Log::error('Voice sample upload failed for user '.$user->id.': '.$e->getMessage());

            return $this->error('Failed to save voice sample. Please try again.', 500);
        }
    }

    /**
     * Get the current voice cloning status for the authenticated user.
     * GET /user/voice-status
     */
    public function getVoiceStatus(Request $request)
    {
        $user = $request->user();

        return $this->success([
            'voice_status'     => $user->voice_status ?? 'none',
            'has_voice'        => $user->voice_status === 'ready',
            'voice_sample_url' => $user->voice_sample_url,
        ]);
    }

    /**
     * Trigger asynchronous audio generation for a book using the author's cloned voice.
     * POST /books/{bookId}/generate-audio
     */
    public function generateAudio(Request $request, int $bookId)
    {
        $user = $request->user();
        $book = Book::find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $isAuthor = $book->author_id === $user->id
            || $book->authors->contains('id', $user->id);

        if (! $isAuthor) {
            return $this->error('You are not authorized to generate audio for this book.', 403);
        }

        if ($user->voice_status !== 'ready' || ! $user->elevenlabs_voice_id) {
            return $this->error('Your voice is not ready yet. Please upload a voice sample and wait for cloning to complete.', 422);
        }

        if (empty($book->files)) {
            return $this->error('This book has no PDF file attached.', 422);
        }

        $dispatched = DB::transaction(function () use ($book, $user) {
            $fresh = Book::where('id', $book->id)->lockForUpdate()->first();

            if (in_array($fresh->audio_status, ['pending', 'processing'])) {
                return false;
            }

            $fresh->update([
                'audio_status'          => 'pending',
                'audio_url'             => null,
                'audio_sample_url'      => null,
                'audio_segments'        => null,
                'audio_duration'        => null,
                'elevenlabs_project_id' => null,
            ]);

            GenerateBookAudioJob::dispatch($fresh, $user)->onQueue('audio');

            return true;
        });

        if (! $dispatched) {
            return $this->error('Audio generation is already in progress for this book.', 422);
        }

        return $this->success([
            'audio_status' => 'pending',
            'book_id'      => $book->id,
        ], 'Audio generation has started. You will be notified when it is ready.');
    }

    /**
     * Admin-only: reset a stuck audio job back to 'none' so the author can retry.
     * POST /books/{bookId}/reset-audio
     */
    public function resetAudio(int $bookId)
    {
        $book = Book::find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        $book->update([
            'audio_status'          => 'none',
            'audio_url'             => null,
            'audio_sample_url'      => null,
            'audio_segments'        => null,
            'audio_duration'        => null,
            'elevenlabs_project_id' => null,
        ]);

        Log::info("Admin manually reset audio status for book {$bookId}.");

        return $this->success(['book_id' => $bookId, 'audio_status' => 'none'], 'Audio status reset. The author can now retry generation.');
    }

    /**
     * Get the current audio generation status and URLs for a book.
     * GET /books/{bookId}/audio-status
     */
    public function getAudioStatus(int $bookId)
    {
        $book = Book::select('id', 'audio_status', 'audio_url', 'audio_sample_url', 'audio_duration', 'audio_segments')
            ->find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        return $this->success([
            'audio_status'     => $book->audio_status ?? 'none',
            'audio_url'        => $book->audio_url,
            'audio_sample_url' => $book->audio_sample_url,
            'audio_duration'   => $book->audio_duration,
            'audio_segments'   => $book->audio_segments ?? [],
        ]);
    }

    /**
     * Admin-only: return ElevenLabs character quota usage.
     * GET /admin/elevenlabs/quota
     */
    public function getElevenLabsQuota(ElevenLabsService $elevenLabs)
    {
        try {
            $quota = $elevenLabs->getQuota();

            return $this->success($quota, $quota['is_low']
                ? 'Warning: ElevenLabs credit is running low (under 10% remaining).'
                : 'ElevenLabs quota fetched successfully.');
        } catch (\Throwable $e) {
            Log::error('Failed to fetch ElevenLabs quota: '.$e->getMessage());

            return $this->error('Could not fetch ElevenLabs quota: '.$e->getMessage(), 500);
        }
    }
}
