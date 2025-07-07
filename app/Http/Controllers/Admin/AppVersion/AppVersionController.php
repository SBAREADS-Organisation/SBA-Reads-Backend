<?php

namespace App\Http\Controllers\Admin\AppVersion;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AppVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class AppVersionController extends Controller
{
    // public function __construct() {
    //     $this->middleware(['auth:sanctum', 'role:admin']);
    //     // $this->middleware('role:admin');
    // }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        try {
            // return response()->json(AppVersion::all(), 200);
            $appVersions = AppVersion::orderBy('created_at', 'desc')->get();

            return $this->success($appVersions, 'App versions retrieved successfully.');
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error('An error occurred while processing your request.', 500, null, $th);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'app_id' => 'required|string',
                'platform' => 'required|in:ios,android,all',
                'version' => 'required|string',
                'force_update' => 'boolean',
                'deprecated' => 'boolean',
                'support_expires_at' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed.', 400, $validator->errors());
            }

            $appVersion = AppVersion::create($request->all());

            return $this->success($appVersion, 'App version created successfully.', 201);
        } catch (\Throwable $th) {
            //throw $th;
            // dd($th);
            return $this->error('An error occurred while processing your request.', 500, null, $th);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $appVersion = AppVersion::find($id);

            if (!$appVersion) {
                return $this->error('App version not found.', 404);
            }

            return $this->success($appVersion, 'App version retrieved successfully.');
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error('An error occurred while processing your request.', 500, null, $th);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $appVersion = AppVersion::find($id);

            if (!$appVersion) {
                return $this->error('App version not found.', 404);
            }

            $validator = Validator::make($request->all(), [
                'app_id' => 'nullable|string',
                'platform' => 'nullable|in:ios,android,all',
                'version' => 'nullable|string',
                'force_update' => 'nullable|boolean',
                'deprecated' => 'nullable|boolean',
                'support_expires_at' => 'nullable|date',
            ]);

            if ($validator->fails()) {
                return $this->error('Validation failed.', 400, $validator->errors());
            }

            $appVersion->update($request->all());

            return $this->success($appVersion, 'App version updated successfully.');
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error('An error occurred while processing your request.', 500, null, $th);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $appVersion = AppVersion::find($id);

            if (!$appVersion) {
                return $this->error('App version not found.', 404);
            }

            $appVersion->delete();

            return $this->success(null, 'App version deleted successfully.', 204);
        } catch (\Throwable $th) {
            //throw $th;
            return $this->error('An error occurred while processing your request.', 500, null, $th);
        }
    }
}
