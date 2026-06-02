<?php

namespace App\Services\AI;

use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class AIReviewService
{
    public function __construct(protected ClaudeService $claude) {}

    /**
     * Review a book submission and return a structured result.
     *
     * @return array{decision: string, confidence: float, reasons: string, concerns: array}
     */
    public function reviewBook(Book $book): array
    {
        $genres   = implode(', ', (array) ($book->genres ?? []));
        $audience = implode(', ', (array) ($book->target_audience ?? []));
        $tags     = implode(', ', (array) ($book->tags ?? []));
        $wordCount = str_word_count($book->description ?? '');

        $userPrompt = <<<PROMPT
Review this book submission for the SBAReads platform:

TITLE: {$book->title}
SUBTITLE: {$book->sub_title}
DESCRIPTION ({$wordCount} words): {$book->description}
GENRES: {$genres}
TARGET AUDIENCE: {$audience}
TAGS: {$tags}
PRICE: {$book->currency} {$book->actual_price}
PUBLISHER: {$book->publisher}

Return ONLY a JSON object with no surrounding text or markdown:
{
  "decision": "approve" | "decline" | "human_review",
  "confidence": 0.00,
  "reasons": "Brief 1-3 sentence explanation.",
  "concerns": ["concern 1", "concern 2"]
}
PROMPT;

        $systemPrompt = <<<'SYSTEM'
You are a content quality reviewer for SBAReads, a professional digital book publishing platform. Review book submissions against these criteria:

APPROVE when:
- Title is meaningful and properly formatted (not gibberish)
- Description is at least 60 words and genuinely describes the book
- At least one genre specified
- Content appears original and lawful
- No red flags (spam, explicit content without context, hate speech)

DECLINE when:
- Description is under 20 words or is clearly placeholder text
- Title is spam, offensive, or nonsensical
- Obvious community standards violation (explicit adult content, hate speech, harmful material)
- Clearly automated spam or a duplicate

FLAG FOR HUMAN REVIEW when:
- Borderline quality — might be genuine but needs a second look
- Unusual or niche content that needs context
- Any uncertainty

Confidence: 0.90+ = very certain, 0.75–0.89 = fairly confident, below 0.75 = uncertain.
Return valid JSON only — no markdown, no extra text.
SYSTEM;

        try {
            $raw    = $this->claude->message($userPrompt, $systemPrompt, 512);
            // Strip markdown code fences Claude sometimes wraps around JSON
            $raw    = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($raw));
            $parsed = json_decode($raw, true);

            if (! isset($parsed['decision'], $parsed['confidence'])) {
                throw new \RuntimeException('Malformed AI response: ' . substr($raw, 0, 200));
            }

            return [
                'decision'   => $parsed['decision'],
                'confidence' => (float) $parsed['confidence'],
                'reasons'    => $parsed['reasons'] ?? '',
                'concerns'   => (array) ($parsed['concerns'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('AIReviewService::reviewBook failed for book ' . $book->id . ': ' . $e->getMessage());
            return [
                'decision'   => 'human_review',
                'confidence' => 0.0,
                'reasons'    => 'AI review failed — flagged for manual inspection.',
                'concerns'   => ['AI service error: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * Review an author account and return a structured result.
     *
     * @return array{decision: string, confidence: float, reasons: string}
     */
    public function reviewAuthor(User $user): array
    {
        $kyc  = $user->kyc_info ?? null;
        $name = $kyc ? "{$kyc->first_name} {$kyc->last_name}" : ($user->name ?? 'Not provided');

        $userPrompt = <<<PROMPT
Review this author account application for the SBAReads platform:

ACCOUNT NAME: {$user->name}
USERNAME: {$user->username}
BIO: {$user->bio}
KYC FULL NAME: {$name}
KYC LOCATION: {$kyc?->city}, {$kyc?->state}, {$kyc?->country}
ACCOUNT TYPE: {$user->account_type}
EMAIL VERIFIED: {$user->email_verified_at}

Return ONLY a JSON object:
{
  "decision": "verify" | "needs_review",
  "confidence": 0.00,
  "reasons": "Brief 1-2 sentence explanation."
}
PROMPT;

        $systemPrompt = <<<'SYSTEM'
You are an author verification reviewer for SBAReads. Determine if an author account should be auto-verified or flagged for human review.

VERIFY when:
- Name appears to be a real person (not "User123", "Test", or random strings)
- Bio exists and is at least a sentence (even if brief)
- KYC information has a real-sounding full name and location

NEEDS REVIEW when:
- Bio is missing or is clearly placeholder
- Name is suspicious (random characters, obviously fake)
- KYC data is absent or incomplete
- Any doubt about legitimacy

Return valid JSON only.
SYSTEM;

        try {
            $raw    = $this->claude->message($userPrompt, $systemPrompt, 256);
            $raw    = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($raw));
            $parsed = json_decode($raw, true);

            if (! isset($parsed['decision'], $parsed['confidence'])) {
                throw new \RuntimeException('Malformed AI response: ' . substr($raw, 0, 200));
            }

            return [
                'decision'   => $parsed['decision'],
                'confidence' => (float) $parsed['confidence'],
                'reasons'    => $parsed['reasons'] ?? '',
            ];
        } catch (\Throwable $e) {
            Log::error('AIReviewService::reviewAuthor failed for user ' . $user->id . ': ' . $e->getMessage());
            return [
                'decision'   => 'needs_review',
                'confidence' => 0.0,
                'reasons'    => 'AI review failed — flagged for manual inspection.',
            ];
        }
    }
}
