<?php

namespace App\Contracts;

use App\Processing\ProcessedContent;
use App\Processing\ProcessingContext;

/**
 * Media processing port. The core iterates over any processor whose
 * supports() matches; concrete processors (OpenAI, pdftotext, ...) are
 * injected by the container.
 */
interface MediaProcessor
{
    public function name(): string;

    public function supports(ProcessingContext $context): bool;

    public function process(string $buffer, ProcessingContext $context): ?ProcessedContent;
}
