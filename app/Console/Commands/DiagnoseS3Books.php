<?php

namespace App\Console\Commands;

use App\Models\Book;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DiagnoseS3Books extends Command
{
    protected $signature = 'diagnose:s3-books
                            {--book= : Check a specific book ID}
                            {--upload-test : Run a live upload+download test against S3}
                            {--limit=10 : How many recent books to scan}';

    protected $description = 'Diagnose S3 book file uploads — checks credentials, bucket connectivity, and whether book PDFs are reachable';

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=yellow>SBA Reads — S3 Book Diagnostics</>');
        $this->line('  ' . str_repeat('─', 50));
        $this->newLine();

        // ── 1. Check S3 configuration ─────────────────────────────────
        $this->line('  <fg=cyan>[1] S3 Configuration</>');

        $key    = config('filesystems.disks.s3.key');
        $secret = config('filesystems.disks.s3.secret');
        $region = config('filesystems.disks.s3.region');
        $bucket = config('filesystems.disks.s3.bucket');
        $url    = config('filesystems.disks.s3.url');

        $this->line("      Key     : " . ($key    ? substr($key, 0, 6) . '***' : '<fg=red>MISSING</>'));
        $this->line("      Secret  : " . ($secret ? '***hidden***'             : '<fg=red>MISSING</>'));
        $this->line("      Region  : " . ($region ?: '<fg=red>MISSING</>'));
        $this->line("      Bucket  : " . ($bucket ?: '<fg=red>MISSING</>'));
        $this->line("      URL     : " . ($url    ?: '(default)'));

        if (! $key || ! $secret || ! $region || ! $bucket) {
            $this->newLine();
            $this->error('  S3 credentials incomplete. Check AWS_* env vars and stop.');
            return 1;
        }

        // ── 2. Live upload + download test ────────────────────────────
        $this->newLine();
        $this->line('  <fg=cyan>[2] Live Upload Test</>');

        if ($this->option('upload-test')) {
            $testKey  = 'diagnose/test-' . Str::uuid() . '.txt';
            $testData = 'SBA Reads S3 test – ' . now()->toIso8601String();

            $put = Storage::disk('s3')->put($testKey, $testData);

            if (! $put) {
                $this->line('      Upload  : <fg=red>FAILED</> — Storage::put() returned false');
                $this->line('      This is the root cause: S3 is rejecting writes.');
                $this->line('      Check bucket permissions, IAM policy, and Object Ownership.');
            } else {
                $this->line('      Upload  : <fg=green>OK</>');

                // Try signed URL
                try {
                    $signed = Storage::disk('s3')->temporaryUrl($testKey, now()->addMinutes(5));
                    $this->line('      Signed  : <fg=green>OK</> — ' . $signed);

                    // Fetch the URL to confirm it works
                    $response = Http::timeout(10)->get($signed);
                    if ($response->successful()) {
                        $this->line('      Fetch   : <fg=green>OK</> — HTTP ' . $response->status());
                    } else {
                        $this->line('      Fetch   : <fg=red>FAILED</> — HTTP ' . $response->status());
                    }
                } catch (\Throwable $e) {
                    $this->line('      Signed  : <fg=red>FAILED</> — ' . $e->getMessage());
                }

                // Also try plain URL
                $plain = Storage::disk('s3')->url($testKey);
                $this->line('      Plain   : ' . $plain);
                try {
                    $r2 = Http::timeout(10)->get($plain);
                    $this->line('      Plain?  : HTTP ' . $r2->status() . ($r2->successful() ? ' <fg=green>public</>' : ' <fg=yellow>private (expected)</>'));
                } catch (\Throwable $e) {
                    $this->line('      Plain?  : <fg=yellow>unreachable</>');
                }

                Storage::disk('s3')->delete($testKey);
                $this->line('      Cleanup : <fg=green>OK</>');
            }
        } else {
            $this->line('      <fg=yellow>Skipped</> — pass --upload-test to run a live S3 write+read test');
        }

        // ── 3. Scan recent books ──────────────────────────────────────
        $this->newLine();
        $this->line('  <fg=cyan>[3] Book File Check</>');

        $query = Book::whereNotNull('files')
            ->where('files', '!=', '[]')
            ->where('files', '!=', 'null')
            ->orderByDesc('created_at');

        if ($id = $this->option('book')) {
            $query = Book::where('id', $id);
        } else {
            $query->limit((int) $this->option('limit'));
        }

        $books = $query->get(['id', 'title', 'files', 'created_at']);

        if ($books->isEmpty()) {
            $this->line('      <fg=yellow>No books found.</>');
            return 0;
        }

        $rows = [];

        foreach ($books as $book) {
            $files = is_array($book->files) ? $book->files : json_decode($book->files, true);

            if (empty($files)) {
                $rows[] = [$book->id, substr($book->title, 0, 30), '<fg=red>no files stored</>', '—', '—'];
                continue;
            }

            $file    = $files[0];
            $url     = $file['public_url'] ?? $file['url'] ?? null;
            $key     = $file['public_id'] ?? null;
            $storage = $url && str_contains($url, 'amazonaws.com') ? 'S3' : ($url ? 'Cloudinary' : '?');

            if (! $url) {
                $rows[] = [$book->id, substr($book->title, 0, 30), '<fg=red>null URL</>', $storage, '—'];
                continue;
            }

            // For S3 files, check if the object actually exists in the bucket
            $exists = '?';
            if ($storage === 'S3' && $key) {
                try {
                    $exists = Storage::disk('s3')->exists($key) ? '<fg=green>exists</>' : '<fg=red>MISSING on S3</>';
                } catch (\Throwable $e) {
                    $exists = '<fg=yellow>check failed: ' . Str::limit($e->getMessage(), 40) . '</>';
                }
            } elseif ($storage === 'Cloudinary') {
                $exists = '<fg=green>Cloudinary (skip)</>';
            }

            $rows[] = [
                $book->id,
                substr($book->title, 0, 30),
                $storage,
                $exists,
                $key ? substr($key, 0, 45) . (strlen($key) > 45 ? '…' : '') : '(no key)',
            ];
        }

        $this->table(
            ['ID', 'Title', 'Storage', 'File Exists?', 'S3 Key'],
            $rows,
        );

        // ── 4. Summary ────────────────────────────────────────────────
        $missing = collect($rows)->filter(fn ($r) => str_contains($r[3] ?? '', 'MISSING'))->count();
        if ($missing > 0) {
            $this->newLine();
            $this->error("  ⚠  {$missing} book(s) have S3 keys stored in the DB but the file does NOT exist on S3.");
            $this->line('     This means the upload silently failed (likely ACL rejection).');
            $this->line('     Fix: deploy the updated BookService (no visibility option) and re-upload the book.');
        } else {
            $this->newLine();
            $this->info('  All checked books have files on the expected storage.');
        }

        $this->newLine();
        return 0;
    }
}
