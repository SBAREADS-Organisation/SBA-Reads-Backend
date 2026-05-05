<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBookAudioJob;
use App\Jobs\VoiceCloningJob;
use App\Models\Book;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AudioController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CloudinaryMediaUploadService $cloudinary
    ) {}

    /**
     * Upload a voice sample to Cloudinary, then dispatch async voice cloning.
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

            // Step 1: Upload to Cloudinary immediately so the file is safely stored
            $uploaded = $this->cloudinary->upload($file, 'voice_samples');

            if ($uploaded instanceof \Illuminate\Http\JsonResponse) {
                return $uploaded;
            }

            // Step 2: Mark status as processing and save the sample URL
            $user->update([
                'voice_sample_url' => $uploaded['url'],
                'voice_status'     => 'processing',
            ]);

            // Step 3: Dispatch async job to clone the voice on ElevenLabs
            $voiceName = ($user->name ?? 'author').'-'.$user->id;
            VoiceCloningJob::dispatch($user->id, $uploaded['url'], $voiceName)
                ->onQueue('voice');

            return $this->success([
                'voice_sample_url' => $uploaded['url'],
                'voice_status'     => 'processing',
                'has_voice'        => false,
            ], 'Voice sample uploaded. Cloning is in progress — you will be notified when it is ready.');

        } catch (\Throwable $e) {
            Log::error('Voice sample upload failed for user '.$user->id.': '.$e->getMessage());

            return $this->error('Failed to upload voice sample. Please try again.', 500, null, $e);
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

        if (in_array($book->audio_status, ['pending', 'processing'])) {
            return $this->error('Audio generation is already in progress for this book.', 422);
        }

        $book->update(['audio_status' => 'pending']);

        GenerateBookAudioJob::dispatch($book, $user)->onQueue('audio');

        return $this->success([
            'audio_status' => 'pending',
            'book_id'      => $book->id,
        ], 'Audio generation has started. You will be notified when it is ready.');
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
}
