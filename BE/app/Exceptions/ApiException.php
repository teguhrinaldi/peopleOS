<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A domain rule violation with a stable machine-readable code.
 * Rendered by the API as {"success":false,"error":{"code","message","details"}}.
 */
class ApiException extends RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function make(string $code, string $message, int $status = 422, array $details = []): self
    {
        return new self($code, $message, $status, $details);
    }
}
