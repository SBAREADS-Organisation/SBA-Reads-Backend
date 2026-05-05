<?php

namespace App\Http\Controllers\Audio;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateBookAudioJob;
use App\Models\Book;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AudioController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected CloudinaryMediaUploadService $cloudinary,
        protected ElevenLabsService $elevenLabs
    ) {}

    /**
     * Upload a voice sample, clone the voice on ElevenLabs, and store the voice_id.
     * POST /user/voice-sample
     */
    public function uploadVoiceSample(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'voice_sample' => 'required|file|mimes:mp3,m4a,wav,mp4,aac,ogg|max:20480',
        ], [
            'voice_sample.required' => 'Please provide a voice sample recording.',
            'voice_sample.mimes' => 'Voice sample must be an audio file (mp3, m4a, wav, mp4, aac, ogg).',
            'voice_sample.max' => 'Voice sample must be under 20 MB.',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors()->first(), 422);
        }

        $user = $request->user();

        try {
            $file = $request->file('voice_sample');

            // Copy the file to a safe temp path before Cloudinary deletes the original
            $ext = $file->getClientOriginalExtension() ?: 'm4a';
            $tempCopyPath = tempnam(sys_get_temp_dir(), 'voice_clone_').'.'.$ext;
            copy($file->getRealPath(), $tempCopyPath);

            // Upload voice sample to Cloudinary for storage
            $uploaded = $this->cloudinary->upload($file, 'voice_samples');

            if ($uploaded instanceof \Illuminate\Http\JsonResponse) {
                @unlink($tempCopyPath);

                return $uploaded;
            }

            // Delete the author's old ElevenLabs voice if one exists (avoid quota creep)
            if ($user->elevenlabs_voice_id) {
                $this->elevenLabs->deleteVoice($user->elevenlabs_voice_id);
            }

            // Clone voice on ElevenLabs using the temp copy
            $voiceName = ($user->name ?? 'author').'-'.$user->id;
            $voiceId = $this->elevenLabs->addVoice($voiceName, $tempCopyPath);
            @unlink($tempCopyPath);

            $user->update([
                'voice_sample_url' => $uploaded['url'],
                'elevenlabs_voice_id' => $voiceId,
            ]);

            return $this->success([
                'voice_sample_url' => $uploaded['url'],
                'has_voice' => true,
            ], 'Voice sample uploaded and cloned successfully.');

        } catch (\Throwable $e) {
            Log::error('Voice sample upload failed for user '.$user->id.': '.$e->getMessage());

            return $this->error('Failed to process voice sample. Please try again.', 500, null, $e);
        }
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

        // Only the book's author(s) may trigger generation
        $isAuthor = $book->author_id === $user->id
            || $book->authors->contains('id', $user->id);

        if (! $isAuthor) {
            return $this->error('You are not authorized to generate audio for this book.', 403);
        }

        if (! $user->elevenlabs_voice_id) {
            return $this->error('Please upload a voice sample before generating audio.', 422);
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
            'book_id' => $book->id,
        ], 'Audio generation has started. This may take several minutes for long books.');
    }

    /**
     * Get the current audio generation status and URLs for a book.
     * GET /books/{bookId}/audio-status
     */
    public function getAudioStatus(int $bookId)
    {
        $book = Book::select('id', 'audio_status', 'audio_url', 'audio_duration', 'audio_segments')
            ->find($bookId);

        if (! $book) {
            return $this->error('Book not found.', 404);
        }

        return $this->success([
            'audio_status' => $book->audio_status ?? 'none',
            'audio_url' => $book->audio_url,
            'audio_duration' => $book->audio_duration,
            'audio_segments' => $book->audio_segments ?? [],
        ]);
    }
}
