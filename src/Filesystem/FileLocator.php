<?php

declare(strict_types=1);

namespace Poseidon\Filesystem;

use Poseidon\Archive\ZipHandle;
use Poseidon\Support\Path;

/**
 * Retrouve un fichier (ou une entrée d'archive imbriquée) à partir de son identifiant signé,
 * après avoir revérifié les racines autorisées.
 */
final class FileLocator
{
    public function __construct(private RootGuard $guard)
    {
    }

    public function locate(string $id): Source
    {
        [$diskPath, $chain, $root] = Source::decodeId($id);
        $this->guard->assertAllowed($diskPath);

        $native = Path::toNative($diskPath);
        if (!is_file($native)) {
            throw new \InvalidArgumentException('Fichier introuvable : il a peut-être été déplacé ou supprimé.');
        }
        $loader = $chain === [] ? null : static fn (): string => ZipHandle::readChain($diskPath, $chain);
        $size = $chain === [] ? (int) @filesize($native) : 0;

        return new Source($diskPath, $chain, $root, $size, (int) @filemtime($native), $loader);
    }

    /** Type MIME pour le téléchargement / l'affichage brut. */
    public static function mimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'pdf' => 'application/pdf',
            'txt', 'log', 'md', 'csv', 'tsv', 'ini', 'conf', 'yml', 'yaml' => 'text/plain; charset=utf-8',
            'json' => 'application/json',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'doc' => 'application/msword',
            'xls' => 'application/vnd.ms-excel',
            'odt' => 'application/vnd.oasis.opendocument.text',
            'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
            'zip' => 'application/zip',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }
}
