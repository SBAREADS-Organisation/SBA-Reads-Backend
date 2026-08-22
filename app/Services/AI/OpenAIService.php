<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', '');
        $this->model  = config('services.openai.model', 'gpt-4o');
    }

    /**
     * Send a message to OpenAI and return the text response.
     * Drop-in replacement for ClaudeService::message().
     */
    public function message(string $userPrompt, string $systemPrompt = '', int $maxTokens = 1024): string
    {
        $messages = [];

        if ($systemPrompt) {
            $messages[] = ['role' => 'system', 'content' => $systemPrompt];
        }

        $messages[] = ['role' => 'user', 'content' => $userPrompt];

        $response = Http::timeout(60)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])
            ->post("{$this->baseUrl}/chat/completions", [
                'model'      => $this->model,
                'max_tokens' => $maxTokens,
                'messages'   => $messages,
            ]);

        if (! $response->successful()) {
            Log::error('OpenAIService failed [HTTP '.$response->status().']: '.substr($response->body(), 0, 500));
            throw new \RuntimeException('AI service unavailable. Please try again later.');
        }

        return $response->json('choices.0.message.content') ?? '';
    }
}
