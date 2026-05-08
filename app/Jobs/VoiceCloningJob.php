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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VoiceCloningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 60, 120];

    public function __construct(
        protected int $userId,
        protected string $localFilePath,
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

        $tempPath = null;

        try {
            // Resolve the file to use — local file if it exists, otherwise re-download from Cloudinary
            if (file_exists($this->localFilePath)) {
                $filePath = $this->localFilePath;
                $ownFile  = false; // don't delete the local storage file yet — keep it until Cloudinary upload done
            } elseif ($user->voice_sample_url) {
                // Local file lost (server restart / disk issue) — re-download from previously saved Cloudinary URL
                Log::warning("VoiceCloningJob: local file missing for user {$this->userId}, re-downloading from Cloudinary");
                $ext      = pathinfo(parse_url($user->voice_sample_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'm4a';
                $tempPath = tempnam(sys_get_temp_dir(), 'voice_clone_').'.'.$ext;
                file_put_contents($tempPath, Http::timeout(60)->get($user->voice_sample_url)->body());
                $filePath = $tempPath;
                $ownFile  = true;
            } else {
                // No file and no previous URL — cannot proceed, notify user to re-upload
                User::where('id', $this->userId)
                    ->where('voice_status', '!=', 'ready')
                    ->update(['voice_status' => 'failed']);
                $notifications->send(
                    $user,
                    'Voice upload required',
                    'Your voice sample was not saved properly. Please upload a new recording.',
                    ['in-app', 'push']
                );
                Log::error("VoiceCloningJob: no local file and no Cloudinary URL for user {$this->userId} — giving up");
                return;
            }

            // Upload to Cloudinary if not already done (local file means Cloudinary upload hasn't happened yet)
            if (! $ownFile) {
                $uploaded = $cloudinary->uploadFromPath(
                    $filePath,
                    'voice_samples',
                    'voice_'.$this->userId.'_'.time()
                );
                $user->update(['voice_sample_url' => $uploaded['url']]);
                @unlink($this->localFilePath);
            }

            // Delete old ElevenLabs voice to avoid quota creep
            if ($user->elevenlabs_voice_id) {
                $elevenLabs->deleteVoice($user->elevenlabs_voice_id);
            }

            // Clone the voice
            $voiceId = $elevenLabs->addVoice($this->voiceName, $filePath);

            if ($ownFile && $tempPath) {
                @unlink($tempPath);
            }

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
            if ($tempPath) {
                @unlink($tempPath);
            }
            Log::error("Voice cloning failed for user {$this->userId}: ".$e->getMessage());

            // Rate-limited: keep status as 'processing', release for 5 minutes without burning a retry
            if (str_starts_with($e->getMessage(), 'ELEVENLABS_RATE_LIMITED')) {
                Log::warning("VoiceCloningJob: rate-limited for user {$this->userId} — releasing for 5 minutes.");
                $this->release(300);
                return;
            }

            // Quota exhausted: fail immediately, no point retrying until quota resets
            if (str_starts_with($e->getMessage(), 'ELEVENLABS_QUOTA_EXCEEDED')) {
                User::where('id', $this->userId)
                    ->where('voice_status', '!=', 'ready')
                    ->update(['voice_status' => 'failed']);
                $notifications->send(
                    $user,
                    'Voice cloning unavailable',
                    'Audio services are temporarily at capacity. Please try again later.',
                    ['in-app', 'push']
                );
                return;
            }

            User::where('id', $this->userId)
                ->where('voice_status', '!=', 'ready')
                ->update(['voice_status' => 'failed']);

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
