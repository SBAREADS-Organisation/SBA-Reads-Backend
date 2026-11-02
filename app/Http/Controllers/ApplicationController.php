<?php

namespace App\Http\Controllers;

use App\Mail\Generic\ApplicationReceived;
use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ApplicationController extends Controller
{
    private const FILE_LINK_DAYS = 7;

    /** Public: submit a listing, publishing or ISBN application. */
    public function store(Request $request)
    {
        // Hidden form field only automated submissions fill in.
        if ($request->filled('website')) {
            return $this->success(null, 'Application received.', 201);
        }

        $type = $request->input('type');
        $needsFiles = in_array($type, ['listing', 'publishing'], true);

        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(Application::TYPES)],
            'book_title' => 'required|string|max:255',
            'author_name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'format' => 'required|string|max:50',
            'package' => [Rule::requiredIf($type === 'isbn'), 'nullable', 'string', 'max:50'],
            'genre' => [Rule::requiredIf($type === 'isbn'), 'nullable', 'string', 'max:100'],
            'consent' => 'accepted',
            'cover' => [Rule::requiredIf($needsFiles), 'nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
            'documents' => [Rule::requiredIf($needsFiles), 'nullable', 'array', 'max:5'],
            'documents.*' => 'file|mimes:pdf,doc,docx,jpg,jpeg,png,zip|max:25600',
        ], [
            'consent.accepted' => 'Please agree to the Privacy Policy and Terms of Use.',
        ]);

        if ($validator->fails()) {
            return $this->error('Please check your application and try again.', 422, $validator->errors());
        }

        try {
            $files = [];
            foreach (array_filter([$request->file('cover'), ...($request->file('documents') ?? [])]) as $file) {
                $files[] = $this->storeFile($file);
            }

            $application = Application::create([
                'type' => $type,
                'book_title' => $request->input('book_title'),
                'author_name' => $request->input('author_name'),
                'email' => $request->input('email'),
                'details' => array_filter($request->only(['format', 'package', 'genre'])),
                'files' => $files,
            ]);
        } catch (\Throwable $e) {
            return $this->error('We could not save your application. Please try again.', 500, null, $e);
        }

        // The application is safely stored at this point, so a mail problem shouldn't fail the request.
        try {
            Mail::to(config('services.applications.notify_email'))
                ->send(new ApplicationReceived($application, $this->fileLinks($application)));
        } catch (\Throwable $e) {
            Log::error('Application notification email failed', ['application_id' => $application->id, 'error' => $e->getMessage()]);
        }

        return $this->success(['id' => $application->id], 'Application received.', 201);
    }

    /** Staff: list applications, newest first. */
    public function index(Request $request)
    {
        $applications = Application::query()
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest()
            ->paginate(min((int) $request->input('items_per_page', 20), 100));

        return $this->success($applications, 'Applications retrieved successfully.');
    }

    /** Staff: one application, with temporary links to its files. */
    public function show(int $id)
    {
        $application = Application::find($id);
        if (! $application) {
            return $this->error('Application not found.', 404);
        }

        return $this->success([
            'application' => $application,
            'file_links' => $this->fileLinks($application),
        ], 'Application retrieved successfully.');
    }

    /** Staff: update status and notes. */
    public function update(Request $request, int $id)
    {
        $application = Application::find($id);
        if (! $application) {
            return $this->error('Application not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => ['sometimes', Rule::in(Application::STATUSES)],
            'admin_notes' => 'sometimes|nullable|string|max:5000',
        ]);
        if ($validator->fails()) {
            return $this->error('Please check your changes and try again.', 422, $validator->errors());
        }

        $application->update($validator->validated());

        return $this->success($application, 'Application updated.');
    }

    /** @return array{name: string, path: string} */
    private function storeFile(UploadedFile $file): array
    {
        $path = Storage::disk('s3')->putFile('applications/' . now()->format('Y/m'), $file);
        if (! $path) {
            throw new \RuntimeException('File upload failed.');
        }

        return ['name' => $file->getClientOriginalName(), 'path' => $path];
    }

    /** @return array<int, array{name: string, url: string}> */
    private function fileLinks(Application $application): array
    {
        return collect($application->files ?? [])->map(function (array $file) {
            try {
                $url = Storage::disk('s3')->temporaryUrl($file['path'], now()->addDays(self::FILE_LINK_DAYS));
            } catch (\Throwable) {
                $url = null;
            }

            return ['name' => $file['name'], 'url' => $url];
        })->filter(fn ($file) => $file['url'])->values()->all();
    }
}
