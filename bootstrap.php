<?php

declare(strict_types=1);

/*
 * Poseidon — point d'amorçage commun (CLI, serveur web, tests).
 * Autoloader PSR-4 minimal : Poseidon\Foo\Bar => src/Foo/Bar.php
 */

const POSEIDON_ROOT = __DIR__;
const POSEIDON_VERSION = '1.0.0';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Poseidon\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = POSEIDON_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

mb_internal_encoding('UTF-8');
