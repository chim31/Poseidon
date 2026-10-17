<?php

declare(strict_types=1);

/*
 * Contrôleur frontal de Poseidon.
 * - Serveur intégré : php -S 127.0.0.1:8080 -t public public/index.php
 * - Apache / Nginx : faire pointer la racine web sur public/ et réécrire vers index.php.
 */

// Serveur intégré de PHP : les fichiers statiques existants sont servis directement.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __FILE__ && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

// Les erreurs PHP sont journalisées, jamais affichées dans les réponses (fuite d'information, JSON cassé).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require dirname(__DIR__) . '/bootstrap.php';

(new Poseidon\Http\Kernel(Poseidon\Config::load()))->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    (string) ($_SERVER['REQUEST_URI'] ?? '/'),
);
