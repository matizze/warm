<?php

namespace App\Integrations;

use App\Data\IncomingMessage;
use App\Data\StatusUpdate;
use App\Integrations\Evolution\EvolutionNormalizer;
use RuntimeException;

/**
 * Dispatches normalization to the normalizer of the configured provider.
 */
final class Normalizer
{
    public function __construct(
        private readonly string $provider,
        private readonly EvolutionNormalizer $evolution,
    ) {}

    public function normalize(array $payload): ?IncomingMessage
    {
        return match ($this->provider) {
            'evolution' => $this->evolution->normalize($payload),
            default => throw new RuntimeException("No normalizer for provider: {$this->provider}"),
        };
    }

    public function normalizeStatus(array $payload): ?StatusUpdate
    {
        return match ($this->provider) {
            'evolution' => $this->evolution->normalizeStatus($payload),
            default => null,
        };
    }
}
