<?php

declare(strict_types=1);

namespace Poseidon\Git;

use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Path;
use Poseidon\Support\Process;

/**
 * Historique Git d'un fichier ou d'une arborescence (lecture seule, sans shell, délais bornés).
 * Les commandes passent « -c safe.directory=* » : un dépôt appartenant à un autre compte reste lisible.
 */
final class GitHistory
{
    private const SEP = "\x1F";

    public static function binary(): ?string
    {
        static $bin = false;
        if ($bin === false) {
            $bin = getenv('POSEIDON_GIT') ?: Process::which('git');
        }

        return $bin ?: null;
    }

    /** Dépôt Git contenant ce chemin (dossier parent qui possède un « .git »), ou null. */
    public static function repository(string $path): ?string
    {
        $dir = Path::normalize(is_dir(Path::toNative($path)) ? $path : dirname($path));
        for ($i = 0; $i < 64; $i++) {
            if (file_exists(Path::toNative($dir . '/.git'))) {
                return $dir;
            }
            $parent = Path::normalize(dirname($dir));
            if ($parent === $dir || $parent === '.') {
                return null;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Commits qui ont modifié un fichier (renommages suivis).
     *
     * @return list<array{hash: string, short: string, author: string, date: string, subject: string}>
     */
    public static function log(string $repo, string $relPath, int $limit = 50): array
    {
        $out = self::git($repo, ['log', '--follow', '-n', (string) $limit, '--format=%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--', $relPath]);

        return self::parseCommits($out);
    }

    /**
     * Commits où le nombre d'occurrences d'un texte (ou d'un motif) a changé, sous un chemin.
     *
     * @return list<array{hash: string, short: string, author: string, date: string, subject: string, files: list<string>}>
     */
    public static function search(string $repo, string $pathspec, string $needle, bool $regex = false, int $limit = 100): array
    {
        if ($needle === '') {
            throw new \InvalidArgumentException('Terme vide.');
        }
        $args = ['log', '-n', (string) $limit, '--format=@@%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--name-only', $regex ? '-G' . $needle : '-S' . $needle];
        if (!$regex) {
            $args[] = '--regexp-ignore-case';
        }
        $args[] = '--';
        $args[] = $pathspec === '' ? '.' : $pathspec;
        $raw = self::git($repo, $args);
        $commits = [];
        foreach (array_filter(explode('@@', $raw)) as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $l): bool => $l !== ''));
            if ($lines === []) {
                continue;
            }
            $head = self::parseCommits(array_shift($lines));
            if ($head !== []) {
                $commits[] = $head[0] + ['files' => $lines];
            }
        }

        return $commits;
    }

    /**
     * Commits récents et fichiers qu'ils modifient, sous un chemin.
     *
     * @return list<array{hash: string, short: string, author: string, date: string, subject: string, files: list<string>}>
     */
    public static function touched(string $repo, string $pathspec, int $limit = 100): array
    {
        $raw = self::git($repo, ['log', '-n', (string) $limit, '--format=@@%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--name-only', '--diff-filter=AMR', '--', $pathspec === '' ? '.' : $pathspec]);
        $commits = [];
        foreach (array_filter(explode('@@', $raw)) as $block) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), static fn (string $l): bool => $l !== ''));
            $head = $lines === [] ? [] : self::parseCommits(array_shift($lines));
            if ($head !== []) {
                $commits[] = $head[0] + ['files' => $lines];
            }
        }

        return $commits;
    }

    /** Contenu d'un fichier à une révision (vide si le fichier n'existait pas). */
    public static function show(string $repo, string $revision, string $relPath, int $maxSize): string
    {
        self::assertRevision($revision);
        $bin = self::binary() ?? throw new UnreadableFileException('Git est introuvable sur ce serveur.', UnreadableFileException::UNSUPPORTED);
        $result = Process::run([$bin, '-c', 'safe.directory=*', '-C', Path::toNative($repo), 'show', $revision . ':' . str_replace('\\', '/', $relPath)], 60, $maxSize);
        if ($result['overflow']) {
            throw new UnreadableFileException('Version trop volumineuse.', UnreadableFileException::TOO_LARGE);
        }

        return $result['code'] === 0 ? $result['stdout'] : '';
    }

    /** Révision parente (ou chaîne vide pour un premier commit). */
    public static function parent(string $repo, string $revision): string
    {
        self::assertRevision($revision);
        $out = trim(self::git($repo, ['rev-list', '--parents', '-n', '1', $revision]));
        $parts = explode(' ', $out);

        return $parts[1] ?? '';
    }

    public static function assertRevision(string $revision): void
    {
        if (!preg_match('/^[0-9a-f]{7,40}$/i', $revision)) {
            throw new \InvalidArgumentException('Identifiant de commit invalide.');
        }
    }

    /** @param list<string> $args */
    private static function git(string $repo, array $args): string
    {
        $bin = self::binary() ?? throw new UnreadableFileException('Git est introuvable sur ce serveur.', UnreadableFileException::UNSUPPORTED);
        $result = Process::run([$bin, '-c', 'safe.directory=*', '-c', 'core.quotepath=false', '-C', Path::toNative($repo), ...$args], 60, 32 * 1024 * 1024);
        if ($result['timedOut']) {
            throw new UnreadableFileException('Historique Git : délai dépassé.', UnreadableFileException::TIMEOUT);
        }
        if ($result['code'] !== 0) {
            throw new \RuntimeException('Git : ' . trim(strtok($result['stderr'], "\n") ?: 'erreur'));
        }

        return $result['stdout'];
    }

    /** @return list<array{hash: string, short: string, author: string, date: string, subject: string}> */
    private static function parseCommits(string $raw): array
    {
        $out = [];
        foreach (explode("\n", trim($raw)) as $line) {
            $f = explode(self::SEP, $line);
            if (count($f) >= 5) {
                $out[] = ['hash' => $f[0], 'short' => $f[1], 'author' => $f[2], 'date' => $f[3], 'subject' => $f[4]];
            }
        }

        return $out;
    }
}
