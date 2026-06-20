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
     * Review a manual-path KYC application (Nigerian / non-Stripe authors).
     *
     * Evaluates: name legitimacy, data completeness, location consistency,
     * phone sanity, age check, and document presence/type.
     *
     * The AI cannot read the document image itself, but document presence
     * and type-appropriateness are weighted positively.
     *
     * @return array{decision: string, confidence: float, reasons: string, flags: array}
     */
    public function reviewKYCApplication(User $user): array
    {
        $kyc = $user->kycInfo ?? $user->kyc_info ?? null;

        $documentPresent = ! empty($kyc?->document_url);
        $documentType    = $kyc?->document_type ?? 'none';
        $country         = strtoupper($kyc?->country ?? '');

        // Map doc type to human label for the prompt
        $docLabel = match ($documentType) {
            'nin_slip'        => 'NIN Slip (Nigeria National Identity)',
            'bvn_slip'        => 'BVN Document (Nigeria Bank Verification)',
            'passport'        => 'International Passport',
            'drivers_license' => "Driver's Licence",
            'national_id'     => 'National ID Card',
            default           => 'None uploaded',
        };

        $dobRaw   = $kyc?->dob ?? null;
        $dob      = $dobRaw ?? 'Not provided';
        if ($dobRaw) {
            try {
                $age = \Carbon\Carbon::parse($dobRaw)->age;
                $dob = "{$dobRaw} (Age: {$age})";
            } catch (\Throwable) {}
        }
        $phone    = $kyc?->phone    ?? 'Not provided';
        $address  = trim(implode(', ', array_filter([
            $kyc?->address_line1,
            $kyc?->city,
            $kyc?->state,
            $country,
        ])));
        $fullName = $kyc ? "{$kyc->first_name} {$kyc->last_name}" : ($user->name ?? 'Not provided');
        $accountName = $user->name ?? 'Not provided';

        $userPrompt = <<<PROMPT
Verify this KYC application for an author on the SBAReads platform.

SUBMITTED PERSONAL INFORMATION:
- Full Name (KYC): {$fullName}
- Account Name: {$accountName}
- Date of Birth: {$dob}
- Phone: {$phone}
- Address: {$address}
- Country: {$country}

IDENTITY DOCUMENT:
- Document Provided: {$documentPresent}
- Document Type: {$docLabel}

Return ONLY a JSON object with no surrounding text or markdown:
{
  "decision": "approve" | "manual_review",
  "confidence": 0.00,
  "reasons": "1-3 sentence explanation of your decision.",
  "flags": ["specific concern 1", "specific concern 2"]
}
PROMPT;

        $systemPrompt = <<<'SYSTEM'
You are a KYC (Know Your Customer) verification specialist for SBAReads, a book publishing platform. Your task is to determine if a manual KYC application looks legitimate enough for automatic approval, or needs a human admin to review it.

APPROVE when ALL of the following hold:
- The name looks like a real person's name (e.g. "Adebayo Okafor", "Fatima Al-Hassan", "Chioma Nwosu") — not "User123", "Test", "Admin", or random strings
- First name and last name are both provided and look plausible for the stated country
- Date of birth is provided and indicates the person is at least 18 years old
- A physical address is provided (city + country at minimum)
- Phone number is provided and has at least 8 digits (not all zeros or obviously fake)
- If country is NG (Nigeria): document type is appropriate (NIN, BVN, passport, driver's licence, national ID)
- If a document was provided, this is a strong positive signal — weight it heavily toward approval
- Overall: the application coheres — name, location, and document type are consistent

FLAG FOR MANUAL REVIEW when ANY of:
- Name looks fake, is a username-style handle, single word, or contains numbers/symbols
- Date of birth is missing or the person appears under 18
- Address is missing or only contains the country name with nothing else
- Phone is missing, too short, or looks like a placeholder (e.g. "0000000000")
- Country is Nigeria but document type is missing ("none") AND no document was uploaded — this is a moderate flag, not automatic rejection, as some authors upload later
- Name-country combination seems implausible (e.g. "Bob Smith" from Nigeria is fine — don't be biased; but "山田太郎" from Nigeria with no explanation might need review)
- Any other significant red flag suggesting the application is not genuine

IMPORTANT GUIDANCE:
- African names are legitimate. Do not penalise unfamiliar names.
- A missing document alone is NOT enough to reject — flag it but keep confidence moderate
- If document IS provided, add 0.10–0.15 to your base confidence
- If name + DOB + phone + address all look real, base confidence should be 0.75–0.85
- Adding a valid document should push confidence to 0.88–0.95
- Be thoughtful, not paranoid. Most authors are legitimate people.

Confidence scale: 0.90+ = very certain approve, 0.80–0.89 = fairly confident, 0.70–0.79 = uncertain, below 0.70 = flag for human.
Return valid JSON only — no markdown, no extra text.
SYSTEM;

        try {
            $raw    = $this->claude->message($userPrompt, $systemPrompt, 512);
            $raw    = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($raw));
            $parsed = json_decode($raw, true);

            if (! isset($parsed['decision'], $parsed['confidence'])) {
                throw new \RuntimeException('Malformed AI response: ' . substr($raw, 0, 200));
            }

            return [
                'decision'   => $parsed['decision'],
                'confidence' => (float) $parsed['confidence'],
                'reasons'    => $parsed['reasons'] ?? '',
                'flags'      => (array) ($parsed['flags'] ?? []),
            ];
        } catch (\Throwable $e) {
            Log::error('AIReviewService::reviewKYCApplication failed for user ' . $user->id . ': ' . $e->getMessage());
            return [
                'decision'   => 'manual_review',
                'confidence' => 0.0,
                'reasons'    => 'AI KYC review failed — requires manual admin inspection.',
                'flags'      => ['AI service error: ' . $e->getMessage()],
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
