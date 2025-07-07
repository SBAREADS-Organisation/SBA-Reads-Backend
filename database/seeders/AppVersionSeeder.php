<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AppVersionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platforms = ['ios', 'android'];
        $versions = ['0.0.1', '0.0.0'];
        $appIds = [
            'com.sbareads',
            'com.sbareads.author',
            'com.sbareads.admin',
            'com.sbareads.reader'
        ];

        $supportExpiresAt = Carbon::now()->addDays(120)->toDateTimeString();
        $now = now()->toDateTimeString();

        foreach ($platforms as $platform) {
            foreach ($appIds as $appId) {
                foreach ($versions as $version) {
                    DB::table('app_versions')->updateOrInsert(
                        [
                            'app_id' => $appId,
                            'platform' => $platform,
                            'version' => $version,
                        ],
                        [
                            'force_update' => false,
                            'deprecated' => false,
                            'support_expires_at' => $supportExpiresAt,
                            'updated_at' => $now,
                            'created_at' => $now,
                        ]
                    );
                }
            }
        }

        if (app()->runningInConsole()) {
            $this->command->info('App versions seeded successfully.');
        } else {
            Log::info('App versions seeded successfully.');
        }
    }
}
