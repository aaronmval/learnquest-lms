<?php

namespace App\Services\Documents;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts plain text from a PDF file. Scanned/image-only PDFs with no
 * embedded text layer are out of scope — OCR is not implemented.
 */
class PdfTextExtractorService
{
    public function extractText(string $absolutePath): string
    {
        $log = Log::channel('ai');
        $startedAt = microtime(true);

        try {
            $pdf = (new Parser)->parseFile($absolutePath);
            $text = $pdf->getText();
        } catch (Throwable $e) {
            $log->error('[pdf] Failed to parse PDF.', [
                'path' => $absolutePath,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to read the PDF file.', 0, $e);
        }

        $normalized = trim(preg_replace('/[ \t]+/', ' ', preg_replace('/\R{2,}/', "\n", $text) ?? ''));

        if ($normalized === '') {
            $log->warning('[pdf] No extractable text found (likely a scanned/image-only PDF).', [
                'path' => $absolutePath,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw new RuntimeException('No readable text could be extracted from this PDF.');
        }

        $log->debug('[pdf] Extracted text.', [
            'path' => $absolutePath,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'char_count' => strlen($normalized),
        ]);

        return $normalized;
    }
}
