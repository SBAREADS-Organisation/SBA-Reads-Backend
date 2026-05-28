<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$book    = App\Models\Book::find(24);
$pdfUrl  = $book->files[0]['public_url'] ?? null;

if (! $pdfUrl) { die("No PDF URL\n"); }

$tempPdf = tempnam(sys_get_temp_dir(), 'dbg_').'.pdf';
file_put_contents($tempPdf, file_get_contents($pdfUrl));

$escaped  = escapeshellarg($tempPdf);
$output   = shell_exec("pdftotext -layout {$escaped} - 2>/dev/null");
$pageParts = explode("\x0C", $output);

echo "Total pages extracted: " . count($pageParts) . "\n\n";

// Print pages 1 through 10 to find the INTRODUCTION page
foreach ($pageParts as $i => $page) {
    $pageNum = $i + 1;
    if ($pageNum < 1 || $pageNum > 10) continue;
    echo "===== PAGE {$pageNum} =====\n";
    // Show first 20 lines of each page
    $lines = explode("\n", $page);
    foreach (array_slice($lines, 0, 20) as $line) {
        echo var_export($line, true) . "\n";
    }
    echo "\n";
}

@unlink($tempPdf);
