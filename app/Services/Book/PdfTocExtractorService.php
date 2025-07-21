<?php

namespace App\Services\Book;

use Illuminate\Support\Facades\Cache;
use LanguageDetection\Language;
use Smalot\PdfParser\Parser;

class PdfTocExtractorService
{
    public function extractBookDetails(string $pdfPath): array
    {
        // Use file hash as cache key
        $cacheKey = 'pdf_table_of_content_'.md5_file($pdfPath);

        return Cache::remember($cacheKey, now()->addHours(12), function () use ($pdfPath) {
            $parser = new Parser;
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            // Get total Number of pages
            $totalPages = count($pdf->getPages());

            $details = [
                'title' => null,
                'sub_title' => null,
                'author' => null,
                'description' => null,
                'language' => null,
                'table_of_contents' => [],
                'total_pages' => $totalPages,
                // 'table_of_contents_tree' => [],
            ];

            // Metadata
            $details = array_merge($details, $this->extractMetadata($pdf->getDetails()));

            // First page scan
            $firstPage = $pdf->getPages()[0] ?? null;
            if ($firstPage) {
                $firstPageText = $firstPage->getText();
                $details = array_merge($details, $this->extractTitleSubtitle($firstPageText));
                $details['description'] = $this->extractDescriptionFromIntro($pdf);
            }

            // Language detection (on first 2 pages)
            $introText = $this->getIntroText($pdf);
            $details['language'] = $this->detectLanguage($introText);

            // TOC
            // dd($text);
            $tocText = $this->extractTocSection($text);
            // dd($tocText);
            $flatToc = $this->parseTocEntries($tocText);

            // dd('FLAT TOC',$flatToc);
            $details['table_of_contents'] = $flatToc;
            // $details['table_of_contents_tree'] = $this->buildTocTree($flatToc);

            return $details;
        });
    }

    private function extractMetadata(array $meta): array
    {
        return [
            'title' => $meta['Title'] ?? null,
            'author' => $meta['Author'] ?? null,
        ];
    }

    private function extractTitleSubtitle(string $text): array
    {
        $lines = explode("\n", $text);
        $lines = array_values(array_filter(array_map('trim', $lines)));

        $title = $lines[0] ?? null;
        $sub_title = $lines[1] ?? null;

        return compact('title', 'sub_title');
    }

    private function getIntroText($pdf): string
    {
        $intro = '';
        foreach (array_slice($pdf->getPages(), 0, 2) as $page) {
            $intro .= "\n".$page->getText();
        }

        return $intro;
    }

    private function extractDescriptionFromIntro($pdf): ?string
    {
        $introText = $this->getIntroText($pdf);

        // Extract "Introduction" section
        if (preg_match('/(Introduction|About (this )?book)(.*?)(?=\n[A-Z]{2,}|\n\d+\s)/is', $introText, $matches)) {
            return trim($matches[0]);
        }

        $lines = explode("\n", strip_tags($introText));
        $descriptionLines = array_slice(array_filter(array_map('trim', $lines)), 2, 5);

        return implode(' ', $descriptionLines);
    }

    private function detectLanguage(string $text): ?string
    {
        try {
            $languageDetector = new Language;
            $results = $languageDetector->detect($text)->bestResults()->close();

            return $results[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function extractTocSection(string $text)
    {
        $lines = explode("\n", $text);
        // dd($lines);
        $tocLines = [];

        $tocKeywords = [
            'table of contents',
            'contents',
            'sommaire',
            'indice',
            'inhalt',
            'index',
            'sumario',
            'toc',
        ];

        $likelyEndKeywords = [
            'appendix',
            'glossary',
            'symbol definitions',
            'symbol',
            'references',
            'bibliography',
        ];

        $startCollecting = false;

        $pageNumberPattern = '/\s+\.{2,}\s*\d+$/';
        $likelyEndPattern = '/^(appendix|glossary|symbol definitions?|references?|bibliography)\b/i';

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $normalized = strtolower(preg_replace('/\s+/', ' ', $trimmed));

            foreach ($likelyEndKeywords as $endKeyword) {
                // if(strtolower($normalized) === 'Symbol') {
                // }
                if (
                    str_contains(strtolower($normalized), strtolower($endKeyword)) &&
                    preg_match('/\d+$/', $normalized)
                ) {
                    // dd('HERE>>>>>>', strtolower($normalized) === 'Symbol', $normalized);
                    break 2;
                }
            }

            // Start collecting when TOC-like keyword is found
            if (! $startCollecting) {
                if ($trimmed === '') {
                    continue;
                }
                foreach ($tocKeywords as $keyword) {
                    if ($normalized === strtolower($keyword)) {
                        // dd('HERE>>>>', true, $trimmed, $normalized, $keyword);
                        $startCollecting = true;

                        continue 2;
                    }
                }
            }

            if ($trimmed === '') {
                continue;
            }

            if ($startCollecting && $trimmed !== '') {
                // dd('STARTED COLLECTING>>>>>', $trimmed);
                $tocLines[] = $trimmed;
                // if ($trimmed !== "1 Introduction 1") {
                //         dd($trimmed);
                //     }
                if (
                    preg_match('/^((\d+\.)+\d*|[A-Z]+\b)?\s*[\w\d\s,:-]+(\.{2,}|[\s]+)\d+$/', $trimmed) || // normal TOC
                    preg_match('/^appendix\s+[A-Z]+\s+.+\d+$/i', $trimmed) // Appendix A Something 120
                ) {
                    $tocLines[] = $trimmed;

                    continue;
                }

                // 3a) Standard numeric sections: 1, 1.1, 2.3.1, etc.
                if (preg_match('/^(\d+(?:\.\d+)*)(?:\s+|\s*\.{2,}\s*)(.+?)\s+(\d+)$/', $trimmed, $m)) {
                    // m[1]=index, m[2]=title, m[3]=page
                    $tocLines[] = $trimmed;

                    continue;
                }

                // 3b) Appendices (Appendix A, Appendix B3, etc.)
                if (preg_match('/^(Appendix\s+[A-Z0-9]+)\s+(.+?)\s+(\d+)$/i', $trimmed, $m)) {
                    $tocLines[] = $trimmed;

                    continue;
                }

                // 3c) Roman numeral chapters: I, II, III, etc.
                if (preg_match('/^([IVXLCDM]+)\s+(.+?)\s+(\d+)$/i', $trimmed, $m)) {
                    $tocLines[] = $trimmed;

                    continue;
                }
            }
        }

        // dd($tocLines);

        // return implode("\n", $tocLines);
        return $tocLines;
    }

    private function parseTocEntries(array $lines): array
    {
        // dd($lines);
        // $lines = preg_split("/\r\n|\n|\r/", $tocText);
        $cleaned = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line)); // normalize spaces
            if (empty($line) || isset($seen[$line])) {
                continue;
            }
            $seen[$line] = true;

            // Remove dot leaders (e.g., ". . . . . . .")
            $line = preg_replace('/\.\s?\.{1,}/', ' ', $line);

            // Regex to match lines like: "3.7.4 Breadth First 30"
            if (preg_match('/^(?<section>\d+(\.\d+)*)(\s+)(?<title>.+?)\s+(?<page>\d+)$/', $line, $matches) ||
            preg_match('/^(?<section>[A-Z]|\d+(\.\d+)*|[A-Z](\.\d+)+)\s+(?<title>.+?)\s+(?<page>\d{1,4})$/', $line, $matches)) {
                $cleaned[] = [
                    'section' => $matches['section'],
                    'title' => trim($matches['title']),
                    'page' => (int) $matches['page'],
                    'level' => substr_count($matches['section'], '.'),
                ];
            } elseif (preg_match_all('/(?<section>[A-Z]|\d+(\.\d+)*|[A-Z](\.\d+)+)\s+(?<title>[^\.]+?)\s+(?<page>\d{1,4})/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $entries[] = [
                        'section' => $match['section'],
                        'title' => trim($match['title']),
                        'page' => (int) $match['page'],
                        'level' => substr_count($match['section'], '.'),
                    ];
                }
            }

            // if (preg_match('/^(?<section>[A-Z](?:\.\d+)*)(\s+)(?<title>.+?)\s+\.{2,}\s*(?<page>\d{1,4})$/', $line, $matches)) {
            //     $cleaned[] = [
            //         'section' => $matches['section'],
            //         'title' => trim($matches['title']),
            //         'page' => (int) $matches['page'],
            //     ];
            // }
        }

        // dd($cleaned);

        return $this->buildHierarchy($cleaned);
    }

    private function buildHierarchy(array $entries): array
    {
        $tree = [];
        $stack = [];

        foreach ($entries as $entry) {
            // Create the node
            $node = [
                'section' => $entry['section'],
                'title' => $entry['title'],
                'page' => $entry['page'],
                'children' => [],
            ];

            // Compute depth from number of dots in section
            $depth = substr_count($entry['section'], '.');

            // Pop any stack entries that are at or deeper than current
            while (! empty($stack) && $stack[count($stack) - 1]['depth'] >= $depth) {
                array_pop($stack);
            }

            if (empty($stack)) {
                // No parent → top-level
                $tree[] = $node;
                // Push to stack as last top-level node
                $stack[] = ['depth' => $depth, 'ref' => &$tree[count($tree) - 1]];
            } else {
                // The parent is the last item on the stack
                $parent = &$stack[count($stack) - 1]['ref']['children'];
                $parent[] = $node;
                // Push this new node onto the stack
                $stack[] = ['depth' => $depth, 'ref' => &$parent[count($parent) - 1]];
            }

            // Unset the temporary reference
            unset($node);
        }

        // dd('TREEE>>>>', $tree);

        return $tree;
    }

    private function buildTocTree(array $toc): array
    {
        $tree = [];
        $stack = [];

        foreach ($toc as $entry) {
            $node = $entry;
            $node['children'] = [];

            while (! empty($stack) && $stack[count($stack) - 1]['level'] >= $node['level']) {
                array_pop($stack);
            }

            if (empty($stack)) {
                $tree[] = $node;
                $stack[] = &$tree[count($tree) - 1];
            } else {
                $parent = &$stack[count($stack) - 1];
                $parent['children'][] = $node;
                $stack[] = &$parent['children'][count($parent['children']) - 1];
            }
        }

        return $tree;
    }
}
