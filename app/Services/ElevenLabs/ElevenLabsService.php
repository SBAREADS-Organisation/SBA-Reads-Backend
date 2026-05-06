<?php

namespace App\Services\ElevenLabs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ElevenLabsService
{
    protected string $apiKey;
    protected string $baseUrl = 'https://api.elevenlabs.io/v1';

    public function __construct()
    {
        $this->apiKey = config('services.elevenlabs.api_key', '');

        if (empty($this->apiKey)) {
            Log::critical('ElevenLabs API key is not configured. Set ELEVENLABS_API_KEY in .env');
        }
    }

    // ─────────────────────────────────────────────
    // Voice cloning
    // ─────────────────────────────────────────────

    public function addVoice(string $name, string $audioFilePath): string
    {
        $response = Http::timeout(120)
            ->withHeaders(['xi-api-key' => $this->apiKey])
            ->attach('files', file_get_contents($audioFilePath), basename($audioFilePath))
            ->post("{$this->baseUrl}/voices/add", ['name' => $name]);

        if (! $response->successful()) {
            $this->logFailure('addVoice', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Voice cloning failed', $response->body());
        }

        $voiceId = $response->json('voice_id');
        if (empty($voiceId)) {
            throw new \RuntimeException('ElevenLabs addVoice returned no voice_id. Response: '.substr($response->body(), 0, 300));
        }

        return $voiceId;
    }

    public function deleteVoice(string $voiceId): bool
    {
        try {
            $response = Http::timeout(30)
                ->withHeaders(['xi-api-key' => $this->apiKey])
                ->delete("{$this->baseUrl}/voices/{$voiceId}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning("ElevenLabs deleteVoice [{$voiceId}] failed: ".$e->getMessage());
            return false;
        }
    }

    // ─────────────────────────────────────────────
    // Text-to-speech (single chunk, fallback)
    // ─────────────────────────────────────────────

    public function generateSpeech(string $voiceId, string $text): string
    {
        $response = Http::timeout(180)->asJson()->withHeaders([
            'xi-api-key' => $this->apiKey,
            'Accept'     => 'audio/mpeg',
        ])->post("{$this->baseUrl}/text-to-speech/{$voiceId}", [
            'text'           => $text,
            'model_id'       => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability'        => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        if (! $response->successful()) {
            $this->logFailure('generateSpeech', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Text-to-speech failed', $response->body());
        }

        return $response->body();
    }

    // ─────────────────────────────────────────────
    // Projects API (long-form audiobook generation)
    // ─────────────────────────────────────────────

    /**
     * Create a new ElevenLabs Project for long-form audio generation.
     * Returns the project_id.
     */
    public function createProject(string $name, string $voiceId): string
    {
        $response = Http::timeout(60)->asJson()->withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->post("{$this->baseUrl}/projects/add", [
            'name'                       => $name,
            'default_title_voice_id'     => $voiceId,
            'default_paragraph_voice_id' => $voiceId,
            'default_model_id'           => 'eleven_multilingual_v2',
            'quality_preset'             => 'standard',
        ]);

        if (! $response->successful()) {
            $this->logFailure('createProject', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Project creation failed', $response->body());
        }

        // Support both nested {"project":{"project_id":"..."}} and flat {"project_id":"..."}
        $projectId = $response->json('project.project_id') ?? $response->json('project_id');

        if (empty($projectId)) {
            Log::error('ElevenLabs createProject returned no project_id. Full response: '.substr($response->body(), 0, 500));
            throw new \RuntimeException('ElevenLabs createProject returned no project_id.');
        }

        return (string) $projectId;
    }

    /**
     * Add a chapter with raw text to a project.
     * Returns the chapter_id.
     */
    public function addChapter(string $projectId, string $name, string $text): string
    {
        $response = Http::timeout(120)->asJson()->withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->post("{$this->baseUrl}/projects/{$projectId}/chapters/add", [
            'name'    => $name,
            'content' => $text,
        ]);

        if (! $response->successful()) {
            $this->logFailure('addChapter', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Chapter add failed', $response->body());
        }

        // Support both nested and flat response shapes
        $chapterId = $response->json('chapter.chapter_id') ?? $response->json('chapter_id');

        if (empty($chapterId)) {
            Log::error("ElevenLabs addChapter returned no chapter_id for project {$projectId}. Response: ".substr($response->body(), 0, 500));
            throw new \RuntimeException('ElevenLabs addChapter returned no chapter_id.');
        }

        return (string) $chapterId;
    }

    /**
     * Start audio conversion for a chapter.
     */
    public function convertChapter(string $projectId, string $chapterId): void
    {
        $response = Http::timeout(60)->asJson()->withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->post("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/convert");

        if (! $response->successful()) {
            $this->logFailure('convertChapter', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Chapter conversion trigger failed', $response->body());
        }
    }

    /**
     * Get the current conversion status of a chapter.
     * Known values: 'default' (queued), 'in_progress', 'done', 'failed'
     */
    public function getChapterStatus(string $projectId, string $chapterId): string
    {
        $response = Http::timeout(30)
            ->withHeaders(['xi-api-key' => $this->apiKey])
            ->get("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}");

        if (! $response->successful()) {
            $this->logFailure('getChapterStatus', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Chapter status fetch failed', $response->body());
        }

        return (string) ($response->json('chapter.status') ?? $response->json('status') ?? 'default');
    }

    /**
     * Get the list of completed audio snapshots for a chapter.
     */
    public function getChapterSnapshots(string $projectId, string $chapterId): array
    {
        $response = Http::timeout(30)
            ->withHeaders(['xi-api-key' => $this->apiKey])
            ->get("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/snapshots");

        if (! $response->successful()) {
            $this->logFailure('getChapterSnapshots', $response->status(), $response->body());
            $this->throwForStatus($response->status(), 'Snapshots fetch failed', $response->body());
        }

        return $response->json('snapshots') ?? [];
    }

    /**
     * Stream the audio for a chapter snapshot directly to a local temp file.
     * Returns the path to the downloaded file. Caller is responsible for unlinking.
     * Streaming avoids loading 100 MB+ audio binaries into PHP memory.
     */
    public function downloadChapterAudioToFile(string $projectId, string $chapterId, string $snapshotId): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'sbareads_audio_').'.mp3';

        $response = Http::timeout(300)
            ->withHeaders(['xi-api-key' => $this->apiKey])
            ->sink($tempPath)
            ->post("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/snapshots/{$snapshotId}/stream");

        if (! $response->successful()) {
            @unlink($tempPath);
            $this->logFailure('downloadChapterAudio', $response->status(), '(binary stream — no body)');
            $this->throwForStatus($response->status(), 'Audio download failed', '');
        }

        if (! file_exists($tempPath) || filesize($tempPath) < 1024) {
            @unlink($tempPath);
            throw new \RuntimeException("ElevenLabs audio download produced an empty or corrupt file for chapter {$chapterId}.");
        }

        return $tempPath;
    }

    /**
     * Delete an ElevenLabs project to free storage quota.
     * Always safe to call — swallows all errors.
     */
    public function deleteProject(string $projectId): void
    {
        try {
            Http::timeout(30)
                ->withHeaders(['xi-api-key' => $this->apiKey])
                ->delete("{$this->baseUrl}/projects/{$projectId}");
        } catch (\Throwable $e) {
            Log::warning("ElevenLabs deleteProject [{$projectId}] failed: ".$e->getMessage());
        }
    }

    // ─────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────

    /**
     * Log a failed API call with the status code and truncated response body.
     */
    private function logFailure(string $method, int $status, string $body): void
    {
        Log::error(sprintf(
            'ElevenLabs %s failed [HTTP %d]: %s',
            $method,
            $status,
            substr($body, 0, 600)
        ));
    }

    /**
     * Throw the appropriate exception based on HTTP status.
     * 429 includes the Retry-After delay in the message so callers can re-queue intelligently.
     */
    private function throwForStatus(int $status, string $context, string $body): never
    {
        if ($status === 429) {
            throw new \RuntimeException("ELEVENLABS_RATE_LIMITED: {$context}. Back off and retry.");
        }

        if ($status === 401 || $status === 403) {
            throw new \RuntimeException("ElevenLabs authentication error [{$status}] in {$context}. Check ELEVENLABS_API_KEY.");
        }

        if ($status >= 500) {
            throw new \RuntimeException("ElevenLabs server error [{$status}] in {$context}. Will retry.");
        }

        throw new \RuntimeException("ElevenLabs {$context} [{$status}]: ".substr($body, 0, 300));
    }
}
