<?php

namespace App\Console\Commands;

use App\Mail\KYC\KycRejectedMail;
use App\Mail\KYC\KycVerifiedMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Account;
use Stripe\Stripe;

class SyncStripeKycStatus extends Command
{
    protected $signature   = 'kyc:sync-stripe {--dry-run : Preview changes without saving}';
    protected $description = 'Sync KYC status for all Stripe-connected authors from Stripe API';

    public function handle(): int
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $users = User::whereNotNull('kyc_account_id')
            ->where('kyc_provider', 'stripe')
            ->where('kyc_status', '!=', 'verified')
            ->get();

        if ($users->isEmpty()) {
            $this->info('No unverified Stripe authors found.');
            return 0;
        }

        $dryRun   = $this->option('dry-run');
        $updated  = 0;
        $errors   = 0;
        $emailed  = 0;

        $this->info("Found {$users->count()} unverified Stripe author(s). " . ($dryRun ? '[DRY RUN]' : ''));
        $this->newLine();

        foreach ($users as $user) {
            try {
                $account        = Account::retrieve($user->kyc_account_id);
                $individual     = $account->individual ?? null;
                $verification   = $individual?->verification ?? null;
                $stripeStatus   = $verification?->status ?? null;
                $docFront       = $verification?->document?->front ?? null;
                $disabledReason = $account->requirements?->disabled_reason ?? null;

                if (! $stripeStatus) {
                    $this->line("  <comment>[SKIP]</comment> {$user->email} — no verification status on Stripe yet");
                    continue;
                }

                $newStatus = $this->resolveStatus($stripeStatus, $docFront, $disabledReason);

                $statusChanged = $newStatus !== $user->kyc_status;
                $indicator     = $statusChanged ? '<info>[UPDATE]</info>' : '<comment>[OK]</comment>';

                $this->line("  {$indicator} {$user->email}");
                $this->line("    DB: {$user->kyc_status} → Stripe: {$stripeStatus} (doc: " . ($docFront ? 'yes' : 'no') . ") → New: {$newStatus}");

                if ($statusChanged && ! $dryRun) {
                    $payload = ['kyc_status' => $newStatus];

                    if ($newStatus === 'verified' && $individual) {
                        $payload['first_name'] = $individual->first_name;
                        $payload['last_name']  = $individual->last_name;
                        $payload['name']       = trim("{$individual->first_name} {$individual->last_name}");
                    }

                    $oldStatus = $user->kyc_status;
                    $user->update($payload);
                    $user->refresh();
                    $updated++;

                    Log::info('kyc:sync-stripe updated user', [
                        'user_id'    => $user->id,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ]);

                    // Send email notification
                    try {
                        if ($newStatus === 'verified') {
                            Mail::to($user->email)->queue(new KycVerifiedMail($user));
                            $this->line("    <info>→ Verified email queued</info>");
                        } elseif ($newStatus === 'rejected') {
                            Mail::to($user->email)->queue(new KycRejectedMail($user));
                            $this->line("    <comment>→ Rejected email queued</comment>");
                        }
                        $emailed++;
                    } catch (\Throwable $mailErr) {
                        $this->line("    <error>→ Email failed: {$mailErr->getMessage()}</error>");
                        Log::warning('kyc:sync-stripe email failed', [
                            'user_id' => $user->id,
                            'error'   => $mailErr->getMessage(),
                        ]);
                    }
                } elseif ($statusChanged) {
                    $updated++;
                }
            } catch (\Throwable $e) {
                $this->line("  <error>[ERROR]</error> {$user->email} — {$e->getMessage()}");
                Log::error('kyc:sync-stripe failed for user', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        $this->newLine();
        $label = $dryRun ? 'Would update' : 'Updated';
        $this->info("{$label}: {$updated} | Emails queued: {$emailed} | Errors: {$errors}");

        return $errors > 0 ? 1 : 0;
    }

    private function resolveStatus(string $stripeStatus, mixed $docFront, ?string $disabledReason): string
    {
        if ($disabledReason && $stripeStatus !== 'verified') {
            return 'rejected';
        }

        return match (true) {
            $stripeStatus === 'verified'                            => 'verified',
            $stripeStatus === 'pending' && $docFront !== null       => 'in-review',
            $stripeStatus === 'pending' && $docFront === null       => 'document-required',
            $stripeStatus === 'unverified' && $docFront !== null    => 'rejected',
            default                                                  => 'document-required',
        };
    }
}
