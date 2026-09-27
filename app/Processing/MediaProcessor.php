<?php

namespace App\Processing;

interface MediaProcessor
{
    public function name(): string;

    public function supports(ProcessingContext $context): bool;

    public function process(string $buffer, ProcessingContext $context): ?ProcessedContent;
}
