<?php

namespace App\Contracts;

use App\Data\IncomingMessage;
use App\Data\StatusUpdate;

/**
 * Inbound port. The core turns raw provider webhook payloads into WARM's
 * internal contracts through this interface only; each provider (Evolution,
 * Z-API, WhatsMeow) supplies a normalizer adapter.
 */
interface MessageNormalizer
{
    public function normalize(array $payload): ?IncomingMessage;

    public function normalizeStatus(array $payload): ?StatusUpdate;
}
