<?php

namespace App\Console\Commands\Analytics;

use App\Mail\Generic\GenericAppNotification;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class GenerateAnalyticsReport extends Command
{
    protected $signature = 'analytics:generate
                            {--scope=admin : Scope of the report (admin/user)}
                            {--user_id= : ID of user (for user scope)}
                            {--format=json : Format of report (json|csv)}
                            {--email= : Email to send report to}';

    protected $description = 'Generate platform or user-specific analytics reports';

    public function handle(AnalyticsService $analyticsService): int
    {
        $scope = $this->option('scope');
        $userId = $this->option('user_id');
        $format = $this->option('format');
        $email = $this->option('email');

        if ($scope === 'user' && ! $userId) {
            $this->error('User ID is required for user scope.');

            return Command::FAILURE;
        }

        $user = $scope === 'admin' ? null : \App\Models\User::find($userId);
        $filters = []; // Add date range etc. if needed

        $this->info("Generating $scope analytics report...");

        $report = $analyticsService->getAnalytics($user, $scope, $filters);

        // Format the report
        $filePath = "reports/analytics_{$scope}_".now()->format('Ymd_His').".{$format}";
        $content = $format === 'csv'
            ? $this->toCsv($report)
            : json_encode($report, JSON_PRETTY_PRINT);

        Storage::disk('local')->put($filePath, $content);
        $this->info("Report saved to storage/app/{$filePath}");

        // Send via email if specified
        if ($email) {
            Mail::to($email)->send(new GenericAppNotification(
                'Analytics Report',
                'Your analytics report is attached.',
                [],
                storage_path("app/{$filePath}")
            ));
            $this->info("Report sent to {$email}");
        }

        return Command::SUCCESS;
    }

    protected function toCsv(array $data): string
    {
        $flattened = collect($data)->map(function ($item, $key) {
            return is_array($item) ? json_encode($item) : $item;
        });

        $headers = implode(',', $flattened->keys()->all());
        $values = implode(',', $flattened->values()->all());

        return $headers."\n".$values;
    }
}
