<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

/**
 * Fichier impossible à lire ou à analyser. Le moteur la convertit en avertissement
 * (Notice) et passe au fichier suivant : un fichier protégé ne bloque jamais un scan.
 */
class UnreadableFileException extends \RuntimeException
{
    public const PROTECTED = 'protected';
    public const DENIED = 'denied';
    public const CORRUPT = 'corrupt';
    public const TOO_LARGE = 'too_large';
    public const TIMEOUT = 'timeout';
    public const UNSUPPORTED = 'unsupported';
    public const LIMIT = 'limit';

    public function __construct(string $message, public readonly string $reason = self::DENIED)
    {
        parent::__construct($message);
    }

    public static function protected(string $what = 'Fichier protégé par mot de passe'): self
    {
        return new self($what . ' : contenu ignoré.', self::PROTECTED);
    }
}
