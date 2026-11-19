<?php

namespace App\Services\Creators;

use App\Mail\Generic\GenericAppNotification;
use App\Services\Slack\SlackWebhookService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tells the SBA team when something in SBA Creators goes wrong that needs a person to look:
 * a payment that couldn't be applied, or a scheduled charge that errored. Never throws.
 */
class CreatorAlerts
{
    public static function send(string $title, array $details = []): void
    {
        Log::error("SBA Creators alert: {$title}", $details);

        try {
            SlackWebhookService::send("SBA Creators: {$title}", $details, 'error');
        } catch (\Throwable $e) {
            Log::warning('SBA Creators Slack alert failed: ' . $e->getMessage());
        }

        $to = config('creators.alert_email');
        if (! $to) {
            return;
        }
        try {
            $lines = collect($details)->map(fn ($v, $k) => ucfirst((string) $k) . ': ' . (is_scalar($v) ? $v : json_encode($v)))->implode("\n");
            Mail::to($to)->queue(new GenericAppNotification("SBA Creators alert: {$title}", "{$title}\n\n{$lines}\n\nTime: " . now()->toDateTimeString()));
        } catch (\Throwable $e) {
            Log::warning('SBA Creators email alert failed: ' . $e->getMessage());
        }
    }
}
