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
        $this->apiKey = config('services.elevenlabs.api_key');
    }

    // ─────────────────────────────────────────────
    // Voice cloning
    // ─────────────────────────────────────────────

    public function addVoice(string $name, string $audioFilePath): string
    {
        $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
            ->attach('files', file_get_contents($audioFilePath), basename($audioFilePath))
            ->post("{$this->baseUrl}/voices/add", ['name' => $name]);

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs voice cloning failed: '.$response->body());
        }

        return $response->json('voice_id');
    }

    public function deleteVoice(string $voiceId): bool
    {
        try {
            $response = Http::withHeaders(['xi-api-key' => $this->apiKey])
                ->delete("{$this->baseUrl}/voices/{$voiceId}");

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('ElevenLabs deleteVoice failed: '.$e->getMessage());

            return false;
        }
    }

    // ─────────────────────────────────────────────
    // Text-to-speech (single chunk, fallback)
    // ─────────────────────────────────────────────

    public function generateSpeech(string $voiceId, string $text): string
    {
        $response = Http::timeout(180)->withHeaders([
            'xi-api-key'   => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept'       => 'audio/mpeg',
        ])->post("{$this->baseUrl}/text-to-speech/{$voiceId}", [
            'text'           => $text,
            'model_id'       => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability'        => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs TTS failed: '.$response->body());
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
        $response = Http::timeout(30)->withHeaders(['xi-api-key' => $this->apiKey])
            ->post("{$this->baseUrl}/projects/add", [
                'name'                          => $name,
                'default_title_voice_id'        => $voiceId,
                'default_paragraph_voice_id'    => $voiceId,
                'default_model_id'              => 'eleven_multilingual_v2',
                'quality_preset'                => 'standard',
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs project creation failed: '.$response->body());
        }

        return $response->json('project.project_id');
    }

    /**
     * Add a chapter with raw text to a project.
     * Returns the chapter_id.
     */
    public function addChapter(string $projectId, string $name, string $text): string
    {
        $response = Http::timeout(30)->withHeaders([
            'xi-api-key'   => $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post("{$this->baseUrl}/projects/{$projectId}/chapters/add", [
            'name'    => $name,
            'content' => $text,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs chapter add failed: '.$response->body());
        }

        return $response->json('chapter.chapter_id');
    }

    /**
     * Start audio conversion for a chapter.
     */
    public function convertChapter(string $projectId, string $chapterId): void
    {
        $response = Http::timeout(30)->withHeaders(['xi-api-key' => $this->apiKey])
            ->post("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/convert");

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs chapter conversion failed: '.$response->body());
        }
    }

    /**
     * Get the current status of a chapter.
     * Status values: 'default' (queued), 'in_progress', 'done', 'failed'
     */
    public function getChapterStatus(string $projectId, string $chapterId): string
    {
        $response = Http::timeout(30)->withHeaders(['xi-api-key' => $this->apiKey])
            ->get("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}");

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs chapter status failed: '.$response->body());
        }

        return $response->json('chapter.status') ?? 'default';
    }

    /**
     * Get the list of snapshots (completed audio outputs) for a chapter.
     */
    public function getChapterSnapshots(string $projectId, string $chapterId): array
    {
        $response = Http::timeout(30)->withHeaders(['xi-api-key' => $this->apiKey])
            ->get("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/snapshots");

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs snapshots fetch failed: '.$response->body());
        }

        return $response->json('snapshots') ?? [];
    }

    /**
     * Download the audio binary for a chapter snapshot.
     */
    public function downloadChapterAudio(string $projectId, string $chapterId, string $snapshotId): string
    {
        $response = Http::timeout(300)->withHeaders(['xi-api-key' => $this->apiKey])
            ->post("{$this->baseUrl}/projects/{$projectId}/chapters/{$chapterId}/snapshots/{$snapshotId}/stream");

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs audio download failed: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Delete a project and free ElevenLabs storage quota.
     */
    public function deleteProject(string $projectId): void
    {
        try {
            Http::timeout(30)->withHeaders(['xi-api-key' => $this->apiKey])
                ->delete("{$this->baseUrl}/projects/{$projectId}");
        } catch (\Throwable $e) {
            Log::warning('ElevenLabs deleteProject failed: '.$e->getMessage());
        }
    }
}
