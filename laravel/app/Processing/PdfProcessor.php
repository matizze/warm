<?php

namespace App\Processing;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts text from PDFs using the poppler `pdftotext` binary, which ships
 * with the OS. No PHP PDF library needed.
 */
final class PdfProcessor implements MediaProcessor
{
    public function name(): string
    {
        return 'pdf';
    }

    public function supports(ProcessingContext $context): bool
    {
        return $context->mimeType === 'application/pdf'
            || str_ends_with(strtolower($context->filename), '.pdf');
    }

    public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
    {
        $process = new Process(['pdftotext', '-', '-']);
        $process->setInput($buffer);
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('pdftotext failed: '.$process->getErrorOutput());
        }

        $text = trim($process->getOutput());
        $pages = substr_count($text, "\f") + ($text === '' ? 0 : 1);

        return new ProcessedContent(
            kind: 'extracted_text',
            text: $text,
            metadata: ['pages' => $pages, 'processor' => $this->name()],
        );
    }
}
