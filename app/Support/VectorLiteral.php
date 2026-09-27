<?php

namespace App\Support;

/**
 * Formats a PHP float list as a pgvector literal. This is a storage detail,
 * so it lives neither in the embedding provider nor in the domain actions.
 */
final class VectorLiteral
{
    /**
     * @param  list<float|int>  $embedding
     */
    public static function fromArray(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }
}
