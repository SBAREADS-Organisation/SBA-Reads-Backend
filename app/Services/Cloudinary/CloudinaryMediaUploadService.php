<?php

namespace App\Services\Cloudinary;

// use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use App\Models\MediaUpload;
use App\Traits\ApiResponse;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class CloudinaryMediaUploadService
{
    use ApiResponse;

    protected Cloudinary $cloudinary;

    public function __construct()
    {
        Configuration::instance([
            'cloud' => [
                'cloud_name' => config('services.cloud.cloud_name'),
                'api_key' => config('services.cloud.api_key'),
                'api_secret' => config('services.cloud.api_secret'),
            ],
            'url' => [
                'secure' => true,
            ],
        ]);
    }

    /**
     * Upload a file to Cloudinary with dynamic foldering and optimization.
     */
    public function upload(UploadedFile $file, string $context = 'general', $mediable = null): array|JsonResponse
    {
        try {
            $folder = $this->resolveFolder($context);
            $fileName = Str::uuid()->toString(); // avoid name collision

            // Attempt to detect morph type from context and assign mediable_type/mediable_id if possible
            $morphType = null;
            $morphMap = Relation::morphMap() ?: [];

            if ($context !== 'general') {
                $parts = explode('_', $context); // e.g. "user_avatar" → ["user", "avatar"]
                $candidate = $parts[0] ?? null;
                // Check if the morph type exists in the morph map
                $morphMap = \Illuminate\Database\Eloquent\Relations\Relation::morphMap() ?: [];
                if (array_key_exists($candidate, $morphMap)) {
                    $morphType = $candidate;
                }
            }

            // If not found, set to null
            if (! isset($morphType)) {
                $morphType = null;
            }

            // dd($morphType, $mediable);

            $uploadOptions = [
                'folder' => $folder,
                'public_id' => $fileName,
                'resource_type' => 'auto', // handles images, videos, pdf, etc.
            ];

            $result = (new UploadApi)->upload($file->getRealPath(), $uploadOptions);

            // $result = Cloudinary::upload($file->getRealPath(), $uploadOptions);

            $media = MediaUpload::create([
                'context' => $context,
                'type' => $result['resource_type'],
                'folder' => $folder,
                'public_id' => $result['public_id'],
                'url' => $result['secure_url'],
                'watermarked' => false,
                'is_temporary' => $context === 'stripe_kyc', // example
                'mediable_type' => $morphType,
                'mediable_id' => $mediable?->id ?? null,
            ]);

            // Delete the file from local storage after upload
            if ($file->isValid() && file_exists($file->getRealPath())) {
                @unlink($file->getRealPath());
            }

            return [
                'url' => $result['secure_url'],
                'public_id' => $result['public_id'],
                'resource_type' => $result['resource_type'],
                'context' => $context,
                'id' => $media->id,
            ];
        } catch (\Exception $e) {
            return $this->error(
                'An error occurred while uploading the file to Cloudinary',
                500,
                config('app.debug') ? $e->getMessage() : null,
            );
        }
    }

    /**
     * Delete a file from Cloudinary.
     */
    public function delete(string $publicId): bool
    {
        // return Cloudinary::destroy($publicId)['result'] === 'ok';
        $response = (new UploadApi)->destroy($publicId, [
            'invalidate' => true,
        ]);

        return isset($response['result']) && $response['result'] === 'ok';
    }

    /**
     * Resolve folders dynamically.
     */
    protected function resolveFolder(string $context): string
    {
        return match ($context) {
            'user_avatar' => 'users/avatars/'.date('Y/m/d'),
            'book_cover' => 'books/covers/'.date('Y/m/d'),
            'book_content' => 'books/content/'.date('Y/m/d'),
            'stripe_kyc' => 'stripe/kyc/'.date('Y/m/d'),
            default => 'general/'.date('Y/m/d')
        };
    }
}
