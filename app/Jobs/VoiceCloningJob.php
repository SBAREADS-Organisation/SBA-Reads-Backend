<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VoiceCloningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300; // 5 min — Cloudinary upload + ElevenLabs cloning

    public array $backoff = [30, 60, 120];

    public function __construct(
        protected int $userId,
        protected string $localFilePath, // absolute path to file saved in storage/app/voice-samples/
        protected string $voiceName
    ) {}

    public function handle(
        ElevenLabsService $elevenLabs,
        NotificationService $notifications,
        CloudinaryMediaUploadService $cloudinary
    ): void {
        $user = User::find($this->userId);

        if (! $user) {
            @unlink($this->localFilePath);
            return;
        }

        try {
            if (! file_exists($this->localFilePath)) {
                throw new \RuntimeException("Voice sample file not found at: {$this->localFilePath}");
            }

            // Step 1: Upload to Cloudinary (now runs in background — no user-facing timeout)
            $uploaded = $cloudinary->uploadFromPath(
                $this->localFilePath,
                'voice_samples',
                'voice_'.$this->userId.'_'.time()
            );

            // Step 2: Save the Cloudinary URL before cloning (so it's persisted even if cloning fails)
            $user->update(['voice_sample_url' => $uploaded['url']]);

            // Step 3: Delete the old ElevenLabs voice to avoid quota creep
            if ($user->elevenlabs_voice_id) {
                $elevenLabs->deleteVoice($user->elevenlabs_voice_id);
            }

            // Step 4: Clone the voice — local file is still available (same process, same disk)
            $voiceId = $elevenLabs->addVoice($this->voiceName, $this->localFilePath);

            // Step 5: Clean up local temp file
            @unlink($this->localFilePath);

            $user->update([
                'elevenlabs_voice_id' => $voiceId,
                'voice_status'        => 'ready',
            ]);

            $notifications->send(
                $user,
                'Your voice is ready!',
                'Your voice sample has been cloned successfully. You can now generate audio for your books.',
                ['in-app', 'push']
            );

            Log::info("Voice cloning complete for user {$user->id}: voice_id={$voiceId}");

        } catch (\Throwable $e) {
            @unlink($this->localFilePath);
            Log::error("Voice cloning failed for user {$this->userId}: ".$e->getMessage());

            User::where('id', $this->userId)->update(['voice_status' => 'failed']);

            if ($this->attempts() >= $this->tries) {
                $notifications->send(
                    $user,
                    'Voice cloning failed',
                    'We could not process your voice sample. Please try uploading a new recording.',
                    ['in-app', 'push']
                );
            }

            throw $e;
        }
    }
}
