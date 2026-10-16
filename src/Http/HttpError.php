<?php

declare(strict_types=1);

namespace Poseidon\Http;

final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
