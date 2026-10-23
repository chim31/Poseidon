<?php

declare(strict_types=1);

/*
 * Point d'entrée « sans terminal » de Poseidon : déposer le dossier sur le serveur web
 * de l'entreprise (IIS, Apache, WAMP, XAMPP…) et ouvrir http://serveur/poseidon/ dans le navigateur.
 *
 * Seuls les fichiers de public/ sont servis tels quels ; tout le reste (var/, config/, src/, .git…)
 * passe par l'application ou est refusé. Les fichiers .htaccess (Apache) et web.config (IIS)
 * fournis à côté de ce fichier bloquent en plus l'accès direct à ces dossiers.
 */

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$urlPath = rawurldecode((string) parse_url($uri, PHP_URL_PATH));

// Fichier statique de public/assets : chemin réel obligatoirement à l'intérieur de public/.
$public = realpath(__DIR__ . '/public');
$assetsAt = strpos($urlPath, '/assets/');
if ($public !== false && $assetsAt !== false && !str_contains($urlPath, "\0")) {
    $candidate = realpath($public . substr($urlPath, $assetsAt));
    $mimes = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'ico' => 'image/x-icon',
    ];
    $ext = strtolower(pathinfo((string) $candidate, PATHINFO_EXTENSION));
    if ($candidate !== false && is_file($candidate) && isset($mimes[$ext])
        && str_starts_with($candidate, $public . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR)) {
        header('Content-Type: ' . $mimes[$ext]);
        header('X-Content-Type-Options: nosniff');
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
