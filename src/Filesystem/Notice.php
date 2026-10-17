<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

/**
 * Avertissement remonté à l'utilisateur (notification dans l'interface, ligne stderr en CLI).
 * $archiveKey : archive qui l'a produit (rejoué par l'index quand l'archive n'est pas rouverte).
 */
final class Notice
{
    public function __construct(
        public readonly string $reason,
        public readonly string $path,
        public readonly string $message,
        public readonly ?string $archiveKey = null,
    ) {
    }

    /** @return array{reason: string, path: string, message: string} */
    public function toArray(): array
    {
        return ['reason' => $this->reason, 'path' => $this->path, 'message' => $this->message];
    }
}
