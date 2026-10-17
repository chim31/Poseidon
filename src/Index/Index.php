<?php

declare(strict_types=1);

namespace Poseidon\Index;

use Poseidon\Config;
use Poseidon\Extractor\Document;
use Poseidon\Filesystem\Notice;
use Poseidon\Filesystem\Source;
use Poseidon\Search\Query;
use Poseidon\Support\Path;

/**
 * Index plein texte persistant d'une racine (SQLite FTS5, tokeniseur trigramme sans accents).
 *
 * L'index ne remplace jamais la vérification : il désigne les fichiers *candidats* (sur-ensemble
 * des fichiers correspondants), que le Matcher vérifie ensuite. Un fichier inchangé et non
 * candidat n'est pas relu ; une archive inchangée sans candidat n'est pas ouverte.
 */
final class Index
{
    private const VERSION = '1';

    private \PDO $db;

    private int $pendingWrites = 0;

    private function __construct(private string $file, public readonly string $root)
    {
        $this->db = new \PDO('sqlite:' . $file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA busy_timeout = 5000;');
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT);
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY,
                disk TEXT NOT NULL,
                chain TEXT NOT NULL,
                size INTEGER NOT NULL,
                mtime INTEGER NOT NULL,
                status TEXT NOT NULL,
                reason TEXT,
                message TEXT,
                generation INTEGER NOT NULL DEFAULT 0,
                UNIQUE (disk, chain)
            );
            CREATE TABLE IF NOT EXISTS archive_notices (
                archive TEXT NOT NULL,
                reason TEXT NOT NULL,
                path TEXT NOT NULL,
                message TEXT NOT NULL
            );
            CREATE INDEX IF NOT EXISTS archive_notices_key ON archive_notices (archive);
            SQL);
        $this->db->exec("CREATE VIRTUAL TABLE IF NOT EXISTS content USING fts5(text, tokenize = 'trigram remove_diacritics 1', content = '', contentless_delete = 1)");
        $this->db->prepare('INSERT OR IGNORE INTO meta (key, value) VALUES (?, ?), (?, ?)')->execute(['version', self::VERSION, 'root', $root]);
    }

    /** SQLite avec FTS5 (trigramme) disponible sur ce PHP ? */
    public static function available(): bool
    {
        static $ok = null;
        if ($ok === null) {
            try {
                $db = new \PDO('sqlite::memory:');
                $db->exec("CREATE VIRTUAL TABLE t USING fts5(x, tokenize = 'trigram remove_diacritics 1', content = '', contentless_delete = 1)");
                $ok = true;
            } catch (\Throwable) {
                $ok = false;
            }
        }

        return $ok;
    }

    public static function directory(Config $config): string
    {
        return (string) $config->get('index_dir', POSEIDON_ROOT . '/var/index');
    }

    public static function fileFor(Config $config, string $root): string
    {
        return self::directory($config) . '/' . sha1(strtolower(Path::normalize($root))) . '.sqlite';
    }

    /** Index existant pour cette racine ou l'un de ses dossiers parents ; null sinon. */
    public static function find(Config $config, string $root): ?self
    {
        if (!self::available()) {
            return null;
        }
        $path = Path::normalize($root);
        for ($i = 0; $i < 64; $i++) {
            $file = self::fileFor($config, $path);
            if (is_file($file)) {
                return new self($file, $path);
            }
            $parent = Path::normalize(dirname($path));
            if ($parent === $path || $parent === '.') {
                break;
            }
            $path = $parent;
        }

        return null;
    }

    public static function create(Config $config, string $root): self
    {
        if (!self::available()) {
            throw new \RuntimeException('Index indisponible : active l\'extension PHP pdo_sqlite (SQLite 3.43 ou plus récent).');
        }
        $dir = self::directory($config);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossible de créer le dossier d\'index : ' . $dir);
        }

        return new self(self::fileFor($config, $root), Path::normalize($root));
    }

    public static function delete(Config $config, string $root): bool
    {
        $file = self::fileFor($config, $root);
        $done = false;
        foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
            if (is_file($f)) {
                $done = @unlink($f) || $done;
            }
        }

        return $done;
    }

    public static function key(string $disk, array $chain): string
    {
        return Source::keyFor($disk, $chain);
    }

    /**
     * État connu d'un fichier : null s'il est inconnu ou modifié depuis l'indexation.
     *
     * @return array{id: int, status: string, reason: ?string, message: ?string}|null
     */
    public function fresh(Source $source): ?array
    {
        $stmt = $this->db->prepare('SELECT id, size, mtime, status, reason, message FROM files WHERE disk = ? AND chain = ?');
        $stmt->execute([Path::normalize($source->diskPath), self::chainJson($source->innerPath)]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['size'] !== $source->size || (int) $row['mtime'] !== $source->mtime) {
            return null;
        }

        return ['id' => (int) $row['id'], 'status' => (string) $row['status'], 'reason' => $row['reason'], 'message' => $row['message']];
    }

    /** Enregistre (ou remplace) l'état et le texte d'un fichier. */
    public function store(Source $source, ?Document $document, ?Notice $notice = null, int $generation = 0): void
    {
        $this->begin();
        $disk = Path::normalize($source->diskPath);
        $chain = self::chainJson($source->innerPath);
        $old = $this->db->prepare('SELECT id FROM files WHERE disk = ? AND chain = ?');
        $old->execute([$disk, $chain]);
        $id = $old->fetchColumn();
        if ($id !== false) {
            $this->db->prepare('DELETE FROM content WHERE rowid = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);
        }
        $status = $notice !== null ? 'notice' : ($document === null ? 'notext' : 'ok');
        $this->db->prepare('INSERT INTO files (disk, chain, size, mtime, status, reason, message, generation) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$disk, $chain, $source->size, $source->mtime, $status, $notice?->reason, $notice?->message, $generation]);
        if ($document !== null) {
            $this->db->prepare('INSERT INTO content (rowid, text) VALUES (?, ?)')->execute([(int) $this->db->lastInsertId(), $document->text()]);
        }
        $this->tick();
    }

    /** Marque un fichier inchangé comme vu pendant une indexation complète. */
    public function touch(int $id, int $generation): void
    {
        $this->begin();
        $this->db->prepare('UPDATE files SET generation = ? WHERE id = ?')->execute([$generation, $id]);
        $this->tick();
    }

    /** Avertissements d'une archive (entrées chiffrées, limites) : rejoués quand l'archive n'est pas rouverte. */
    public function clearArchiveNotices(string $archiveKey): void
    {
        $this->begin();
        $this->db->prepare('DELETE FROM archive_notices WHERE archive = ?')->execute([$archiveKey]);
        $this->tick();
    }

    public function addArchiveNotice(string $archiveKey, Notice $notice): void
    {
        $this->begin();
        $this->db->prepare('INSERT INTO archive_notices (archive, reason, path, message) VALUES (?, ?, ?, ?)')->execute([$archiveKey, $notice->reason, $notice->path, $notice->message]);
        $this->tick();
    }

    /** @return list<Notice> */
    public function archiveNotices(string $archiveKey): array
    {
        $stmt = $this->db->prepare("SELECT reason, path, message FROM archive_notices WHERE archive = ? OR archive LIKE ? ESCAPE '\\' ORDER BY rowid");
        $stmt->execute([$archiveKey, self::likePrefix($archiveKey)]);

        // Sans clé d'archive : un avertissement rejoué ne doit pas être réenregistré.
        return array_map(static fn (array $r): Notice => new Notice($r['reason'], $r['path'], $r['message']), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Fichiers candidats pour un filtre, ou null si l'index ne peut pas répondre
     * (expression régulière, fragment de moins de 3 caractères).
     *
     * @return array{ids: array<int, true>, archives: array<string, true>}|null
     */
    public function candidates(Query $query): ?array
    {
        if ($query->mode === Query::REGEX || $query->fuzzy) {
            return null; // motif ou mots approchés : l'index (fragments exacts) ne peut pas répondre
        }
        $parts = [];
        foreach ($query->terms as $term) {
            preg_match_all('/[\p{L}\p{N}_]+/u', $term, $m);
            if ($m[0] === []) {
                return null;
            }
            $pieces = [];
            foreach ($m[0] as $piece) {
                if (mb_strlen($piece) < 3) {
                    return null;
                }
                $pieces[] = '"' . $piece . '"';
            }
            $parts[] = '(' . implode(' AND ', $pieces) . ')';
        }
        $match = implode($query->mode === Query::ANY ? ' OR ' : ' AND ', $parts);

        $stmt = $this->db->prepare('SELECT f.id, f.disk, f.chain FROM content c JOIN files f ON f.id = c.rowid WHERE content MATCH ?');
        $stmt->execute([$match]);
        $ids = [];
        $archives = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $ids[(int) $row['id']] = true;
            $chain = json_decode((string) $row['chain'], true) ?: [];
            // Toutes les archives qui contiennent ce candidat doivent être ouvertes.
            for ($k = 0; $k < count($chain); $k++) {
                $archives[self::key($row['disk'], array_slice($chain, 0, $k))] = true;
            }
        }

        return ['ids' => $ids, 'archives' => $archives];
    }

    /** Supprime les fichiers non vus lors de l'indexation complète $generation (fichiers effacés). */
    public function prune(int $generation): int
    {
        $this->begin();
        $this->db->prepare('DELETE FROM content WHERE rowid IN (SELECT id FROM files WHERE generation < ?)')->execute([$generation]);
        $stmt = $this->db->prepare('DELETE FROM files WHERE generation < ?');
        $stmt->execute([$generation]);
        $this->commit();

        return $stmt->rowCount();
    }

    public function nextGeneration(): int
    {
        return (int) $this->db->query('SELECT COALESCE(MAX(generation), 0) + 1 FROM files')->fetchColumn();
    }

    public function setMeta(string $key, string $value): void
    {
        $this->db->prepare('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)')->execute([$key, $value]);
    }

    /** @return array{root: string, files: int, withText: int, notices: int, builtAt: ?string, sizeBytes: int} */
    public function stats(): array
    {
        $count = static fn (\PDO $db, string $where): int => (int) $db->query('SELECT COUNT(*) FROM files' . $where)->fetchColumn();
        $built = $this->db->query("SELECT value FROM meta WHERE key = 'built_at'")->fetchColumn();

        return [
            'root' => Path::display($this->root),
            'files' => $count($this->db, ''),
            'withText' => $count($this->db, " WHERE status = 'ok'"),
            'notices' => $count($this->db, " WHERE status = 'notice'"),
            'builtAt' => $built === false ? null : (string) $built,
            'sizeBytes' => (int) @filesize($this->file) + (int) @filesize($this->file . '-wal'),
        ];
    }

    public function commit(): void
    {
        if ($this->db->inTransaction()) {
            $this->db->commit();
        }
        $this->pendingWrites = 0;
    }

    public function __destruct()
    {
        try {
            $this->commit();
        } catch (\Throwable) {
            // base verrouillée ou fermée : rien à sauver de plus
        }
    }

    private function begin(): void
    {
        if (!$this->db->inTransaction()) {
            $this->db->beginTransaction();
        }
    }

    private function tick(): void
    {
        if (++$this->pendingWrites >= 500) {
            $this->commit();
        }
    }

    private static function chainJson(array $chain): string
    {
        return (string) json_encode(array_values($chain), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function likePrefix(string $archiveKey): string
    {
        // Archives imbriquées : même disque, chaîne qui prolonge celle de l'archive.
        $base = substr($archiveKey, 0, -1); // retire le « ] » final du JSON
        $base = rtrim($base, '[');

        return str_replace(['%', '_'], ['\%', '\_'], $base) . '%';
    }
}
