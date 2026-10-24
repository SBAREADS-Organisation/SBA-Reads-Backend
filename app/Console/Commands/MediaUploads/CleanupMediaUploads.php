<?php

namespace App\Console\Commands\MediaUploads;

use Illuminate\Console\Command;

class CleanupMediaUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-media-uploads';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        \App\Models\MediaUpload::onlyTrashed()
            ->where('deleted_at', '<=', now()->subDays(7))
            ->chunkById(200, function ($chunk) {
                foreach ($chunk as $media) {
                    try {
                        \CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary::destroy($media->public_id);
                        $media->forceDelete();
                        $this->info("Deleted: {$media->public_id}");
                    } catch (\Exception $e) {
                        $this->error("Failed to delete {$media->public_id}: ".$e->getMessage());
                    }
                }
            });

        return Command::SUCCESS;
    }
}
