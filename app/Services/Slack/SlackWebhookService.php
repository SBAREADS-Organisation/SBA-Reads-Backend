<?php

namespace App\Services\Slack;

use Illuminate\Support\Facades\Http;

class SlackWebhookService
{
    public static function send(string $title, array $data = [], string $type = 'info'): void
    {
        $webhookUrl = config('logging.channels.slack.webhook_url') ?? config('logging.slack.webhook_url');

        if (! $webhookUrl) {
            // dd('Slack webhook URL is not set. Skipping Slack notification.'.' '.$webhookUrl);
            logger()->warning('Slack webhook URL is not set. Skipping Slack notification.');

            return;
        }

        $color = match ($type) {
            'error' => '#FF3B30', // 'danger',
            'warning' => '#FFA500', // 'warning',
            'success' => '#008000', // 'good',
            default => '#439FE0',
        };

        $channel = config('logging.channels.slack.channel') ?? config('logging.slack.channel');
        $username = config('logging.channels.slack.username') ?? config('logging.slack.username', 'Webhook Bot');
        $icon = config('logging.channels.slack.icon') ?? config('logging.slack.icon', ':ghost:');

        $payload = [
            'channel' => $channel,
            'username' => $username,
            'icon_emoji' => $icon,
            'attachments' => [[
                'color' => $color,
                'title' => $title,
                'fields' => array_map(fn ($key, $value) => [
                    'title' => ucfirst($key),
                    'value' => is_string($value) ? $value : json_encode($value, JSON_PRETTY_PRINT),
                    'short' => false,
                ], array_keys($data), $data),
                'ts' => time(),
            ]],
        ];

        $response = Http::post($webhookUrl, $payload);
    }
}
