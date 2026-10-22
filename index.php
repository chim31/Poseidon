<?php

declare(strict_types=1);

/*
 * Contrôleur frontal principal de Poseidon (Lancement sans terminal).
 * - Serveur web d'entreprise (IIS, Apache, Nginx, WAMP, etc.) : accessible directement via navigateur.
 * - Aucune ligne de commande requise : répond directement sur les hôtes configurés.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$urlPath = (string) parse_url($uri, PHP_URL_PATH);

// Service direct des fichiers statiques existants de public/assets
$candidates = [
    __DIR__ . '/public' . $urlPath,
    __DIR__ . '/public/' . ltrim($urlPath, '/'),
];
if (str_contains($urlPath, '/assets/')) {
    $assetsSub = substr($urlPath, strpos($urlPath, '/assets/'));
    $candidates[] = __DIR__ . '/public' . $assetsSub;
}

$mimes = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'svg' => 'image/svg+xml',
    'woff2' => 'font/woff2',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'ico' => 'image/x-icon',
    'html' => 'text/html; charset=utf-8',
];

foreach ($candidates as $candidate) {
    if (is_file($candidate) && !str_ends_with($candidate, '.php')) {
        $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . filesize($candidate));
        readfile($candidate);
        exit;
    }
}

require __DIR__ . '/bootstrap.php';

(new Poseidon\Http\Kernel(Poseidon\Config::load()))->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $uri,
);
