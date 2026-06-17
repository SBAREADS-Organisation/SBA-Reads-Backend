<?php

namespace Database\Seeders;

use App\Models\Subscription;
use Illuminate\Database\Seeder;

class SubscriptionSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'title'            => 'Basic Reader',
                'price'            => 0,
                'duration_in_days' => 30,
                'model'            => 'monthly',
                'currencies'       => ['usd', 'ngn'],
                'perks'            => [
                    'Access to free books',
                    'Basic reading experience',
                    'Bookmarks and reading progress',
                ],
            ],
            [
                'title'            => 'Standard Reader',
                'price'            => 7.99,
                'duration_in_days' => 30,
                'model'            => 'monthly',
                'currencies'       => ['usd'],
                'perks'            => [
                    'Everything in Basic',
                    'Access to premium books',
                    'Offline reading',
                    'Ask the Book (AI chat)',
                    'Priority support',
                ],
            ],
            [
                'title'            => 'Premium Reader',
                'price'            => 14.99,
                'duration_in_days' => 30,
                'model'            => 'monthly',
                'currencies'       => ['usd'],
                'perks'            => [
                    'Everything in Standard',
                    'Unlimited audiobook access',
                    'Early access to new releases',
                    'Exclusive author content',
                    'Ad-free experience',
                ],
            ],
        ];

        foreach ($plans as $plan) {
            Subscription::updateOrCreate(
                ['title' => $plan['title']],
                $plan
            );
        }
    }
}
