<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ElevenLabs\ElevenLabsService;
use App\Services\Notification\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceCloningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $backoff = 30;

    public function __construct(
        protected int $userId,
        protected string $voiceSampleUrl,
        protected string $voiceName
    ) {}

    public function handle(ElevenLabsService $elevenLabs, NotificationService $notifications): void
    {
        $user = User::find($this->userId);

        if (! $user) {
            return;
        }

        $tempPath = null;

        try {
            // Download the voice sample from Cloudinary to a local temp file
            $ext      = pathinfo(parse_url($this->voiceSampleUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'm4a';
            $tempPath = tempnam(sys_get_temp_dir(), 'voice_clone_').'.'.$ext;
            $audioData = Http::timeout(60)->get($this->voiceSampleUrl)->body();
            file_put_contents($tempPath, $audioData);

            // Delete the old ElevenLabs voice to avoid quota creep
            if ($user->elevenlabs_voice_id) {
                $elevenLabs->deleteVoice($user->elevenlabs_voice_id);
            }

            // Clone the voice on ElevenLabs
            $voiceId = $elevenLabs->addVoice($this->voiceName, $tempPath);
            @unlink($tempPath);

            $user->update([
                'elevenlabs_voice_id' => $voiceId,
                'voice_status'        => 'ready',
            ]);

            // Notify the author their voice is ready
            $notifications->send(
                $user,
                'Your voice is ready!',
                'Your voice sample has been cloned successfully. You can now generate audio for your books.',
                ['in-app', 'push']
            );

            Log::info("Voice cloning complete for user {$user->id}: voice_id={$voiceId}");

        } catch (\Throwable $e) {
            if ($tempPath) {
                @unlink($tempPath);
            }
            Log::error("Voice cloning failed for user {$this->userId}: ".$e->getMessage());

            User::where('id', $this->userId)->update(['voice_status' => 'failed']);

            // Notify on final failure (after all retries exhausted)
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
