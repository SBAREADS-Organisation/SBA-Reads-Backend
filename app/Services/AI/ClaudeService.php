<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
        $this->model  = config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * Send a message to Claude and return the text response.
     */
    public function message(string $userPrompt, string $systemPrompt = '', int $maxTokens = 1024): string
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'messages'   => [['role' => 'user', 'content' => $userPrompt]],
        ];

        if ($systemPrompt) {
            $payload['system'] = $systemPrompt;
        }

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])
            ->post("{$this->baseUrl}/messages", $payload);

        if (! $response->successful()) {
            Log::error('ClaudeService failed [HTTP '.$response->status().']: '.substr($response->body(), 0, 500));
            throw new \RuntimeException('AI service unavailable. Please try again later.');
        }

        return $response->json('content.0.text') ?? '';
    }
}
