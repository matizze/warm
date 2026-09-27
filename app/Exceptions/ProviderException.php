<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by provider adapters (Evolution, ...) for expected provider failures:
 * HTTP errors, missing or invalid media responses. It carries the real cause
 * so callers and the exception handler can distinguish an expected provider
 * failure from a programming error without catching Throwable locally.
 */
class ProviderException extends RuntimeException {}
