<?php

namespace App\Traits\Slack;

use App\Services\Slack\SlackWebhookService;

trait SlackNotifiable
{
    public function notifySlack(string $title, array $data = [], string $type = 'info'): void
    {
        SlackWebhookService::send($title, $data, $type);
    }
}
