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

    /**
     * Clone a voice from an audio sample file.
     * Returns the ElevenLabs voice_id for future TTS requests.
     */
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

    /**
     * Generate speech (MP3 binary) from text using a cloned voice.
     */
    public function generateSpeech(string $voiceId, string $text): string
    {
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'audio/mpeg',
        ])->post("{$this->baseUrl}/text-to-speech/{$voiceId}", [
            'text' => $text,
            'model_id' => 'eleven_multilingual_v2',
            'voice_settings' => [
                'stability' => 0.5,
                'similarity_boost' => 0.75,
            ],
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('ElevenLabs TTS failed: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Delete a cloned voice from ElevenLabs.
     */
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
}
