<?php

namespace App\Http\Controllers\MediaUploader;

use App\Http\Controllers\Controller;
use App\Services\Cloudinary\CloudinaryMediaUploadService;
// use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class MediaUploadController extends Controller
{
    // use ApiResponse;
    protected $media_service;

    public function __construct(CloudinaryMediaUploadService $media_service)
    {
        $this->media_service = $media_service;
    }

    public function upload(Request $request)
    {
        // $cloudinaryImage = $request->file('image')->storeOnCloudinary('products');
        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'context' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->error($validator->errors(), 422, 'Validation failed.');
        }

        // dd($validator);

        // $context_part = explode('_', $validator->);

        $result = $this->media_service->upload($request->file('file'), $request->context, $request->user());

        return $this->success($result, 'Upload successful.', 200);
    }
}
