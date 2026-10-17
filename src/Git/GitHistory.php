<?php

declare(strict_types=1);

namespace Poseidon\Git;

use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Path;
use Poseidon\Support\Process;

/**
 * Historique Git d'un fichier ou d'une arborescence (lecture seule, sans shell, délais bornés).
 *
 * Un dépôt peut être piégé : sa configuration locale peut déclarer des commandes que Git exécute
 * (core.fsmonitor, filtres, textconv, diff externe…). Poseidon neutralise ces réglages à chaque appel,
 * ignore les configurations système et globale, et garde la protection « safe.directory » de Git :
 * un dépôt appartenant à un autre compte n'est lu que s'il figure dans git_trusted_repos.
 */
final class GitHistory
{
    private const SEP = "\x1F";

    /** Dépôts appartenant à un autre compte mais déclarés sûrs (configuration git_trusted_repos). */
    private static array $trusted = [];

    /** @param list<string> $repos */
    public static function trust(array $repos): void
    {
        self::$trusted = array_values(array_map(static fn (string $r): string => str_replace('\\', '/', Path::normalize($r)), $repos));
    }

    /** Options qui désactivent tout ce qu'un dépôt piégé pourrait faire exécuter. */
    private static function hardening(): array
    {
        $none = 'poseidon-programme-desactive'; // introuvable : jamais exécuté
        $opts = [
            '-c', 'core.fsmonitor=false', '-c', 'core.hooksPath=' . (DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null'),
            '-c', 'diff.external=', '-c', 'core.pager=cat', '-c', 'core.quotepath=false', '-c', 'protocol.allow=never',
            // Vérification de signatures : un dépôt piégé pourrait désigner son propre « gpg ».
            '-c', 'log.showSignature=false', '-c', 'gpg.program=' . $none, '-c', 'gpg.ssh.program=' . $none, '-c', 'gpg.x509.program=' . $none,
            '-c', 'log.mailmap=false', '-c', 'core.sshCommand=' . $none,
        ];
        foreach (self::$trusted as $repo) {
            array_push($opts, '-c', 'safe.directory=' . $repo);
        }

        return $opts;
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $null = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        return ['GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => $null, 'GIT_TERMINAL_PROMPT' => '0', 'GIT_EXTERNAL_DIFF' => '', 'GIT_ASKPASS' => '', 'SSH_ASKPASS' => ''];
    }

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
        $out = self::git($repo, ['log', '--no-textconv', '--no-ext-diff', '--no-show-signature', '--no-notes', '--follow', '-n', (string) $limit, '--format=%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--', $relPath]);

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
        $args = ['log', '--no-textconv', '--no-ext-diff', '--no-show-signature', '--no-notes', '-n', (string) $limit, '--format=@@%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--name-only', $regex ? '-G' . $needle : '-S' . $needle];
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
        $raw = self::git($repo, ['log', '--no-textconv', '--no-ext-diff', '--no-show-signature', '--no-notes', '-n', (string) $limit, '--format=@@%H%x1F%h%x1F%an%x1F%aI%x1F%s', '--name-only', '--diff-filter=AMR', '--', $pathspec === '' ? '.' : $pathspec]);
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
        // « cat-file blob » : contenu brut, sans filtre ni textconv du dépôt.
        $result = Process::run([$bin, ...self::hardening(), '-C', Path::toNative($repo), 'cat-file', 'blob', $revision . ':' . str_replace('\\', '/', $relPath)], 60, $maxSize, self::environment());
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
        $result = Process::run([$bin, ...self::hardening(), '-C', Path::toNative($repo), ...$args], 60, 32 * 1024 * 1024, self::environment());
        if ($result['timedOut']) {
            throw new UnreadableFileException('Historique Git : délai dépassé.', UnreadableFileException::TIMEOUT);
        }
        if ($result['code'] !== 0) {
            if (str_contains($result['stderr'], 'dubious ownership')) {
                throw new \RuntimeException('Dépôt Git appartenant à un autre compte : ajoute-le à git_trusted_repos (POSEIDON_GIT_TRUSTED) s\'il est sûr.');
            }
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
