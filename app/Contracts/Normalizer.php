<?php

namespace App\Contracts;

use App\Data\IncomingMessage;
use App\Data\StatusUpdate;

/**
 * Inbound port. The application only knows this contract; each provider
 * (Evolution, Z-API, WhatsMeow) supplies an implementation that converts its
 * webhook payloads into WARM's internal contracts.
 */
interface Normalizer
{
    public function normalize(array $payload): ?IncomingMessage;

    public function normalizeStatus(array $payload): ?StatusUpdate;
}
