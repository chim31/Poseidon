<?php

declare(strict_types=1);

namespace Poseidon\Auth;

use Poseidon\Config;
use Poseidon\Support\Path;

/**
 * Comptes utilisateurs (var/users.json) : mot de passe haché (password_hash), rôle, racines autorisées.
 *
 * Rôles :
 *  - admin   : tout, y compris l'index, la suppression des modèles et le journal d'audit ;
 *  - lecteur : rechercher, consulter, télécharger, extraire, utiliser et enregistrer des modèles.
 */
final class UserStore
{
    public const ROLES = ['admin', 'lecteur'];

    public function __construct(private Config $config)
    {
    }

    public function file(): string
    {
        return (string) $this->config->get('users_file', POSEIDON_ROOT . '/var/users.json');
    }

    /** @return array<string, array{hash: string, role: string, roots: list<string>, createdAt: string}> */
    public function all(): array
    {
        $file = $this->file();
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : [];
    }

    public function enabled(): bool
    {
        return $this->all() !== [];
    }

    /** @return array{login: string, role: string, roots: list<string>}|null */
    public function get(string $login): ?array
    {
        $user = $this->all()[self::normalize($login)] ?? null;

        return $user === null ? null : ['login' => self::normalize($login), 'role' => $user['role'], 'roots' => $user['roots'] ?? []];
    }

    /** @return array{login: string, role: string, roots: list<string>}|null */
    public function verify(string $login, string $password): ?array
    {
        $login = self::normalize($login);
        $users = $this->all();
        $user = $users[$login] ?? null;
        if ($user === null) {
            password_verify($password, '$2y$12$dTa.EhIPSFb4z6rUwgP8ZeCgGfEpRBy6SW3j25WsnKMBE54.L9U8G'); // temps constant

            return null;
        }
        if (!password_verify($password, $user['hash'])) {
            return null;
        }
        if (password_needs_rehash($user['hash'], PASSWORD_DEFAULT)) {
            $users[$login]['hash'] = password_hash($password, PASSWORD_DEFAULT);
            $this->write($users);
        }

        return ['login' => $login, 'role' => $user['role'], 'roots' => $user['roots'] ?? []];
    }

    /** @param list<string> $roots */
    public function save(string $login, string $password, string $role = 'lecteur', array $roots = []): void
    {
        $login = self::normalize($login);
        if (!preg_match('/^[a-z0-9._@-]{2,64}$/', $login)) {
            throw new \InvalidArgumentException('Identifiant invalide : 2 à 64 caractères parmi a-z, 0-9, . _ @ -');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Rôle inconnu : ' . $role . ' (admin ou lecteur).');
        }
        $users = $this->all();
        if ($password === '' && !isset($users[$login])) {
            throw new \InvalidArgumentException('Mot de passe obligatoire pour un nouveau compte.');
        }
        if ($password !== '' && mb_strlen($password) < 10) {
            throw new \InvalidArgumentException('Mot de passe trop court : 10 caractères au minimum.');
        }
        $users[$login] = [
            'hash' => $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $users[$login]['hash'],
            'role' => $role,
            'roots' => array_values(array_map([Path::class, 'normalize'], array_filter($roots, static fn (string $r): bool => trim($r) !== ''))),
            'createdAt' => $users[$login]['createdAt'] ?? date(DATE_ATOM),
        ];
        $this->write($users);
    }

    public function delete(string $login): bool
    {
        $users = $this->all();
        $login = self::normalize($login);
        if (!isset($users[$login])) {
            return false;
        }
        unset($users[$login]);
        $this->write($users);

        return true;
    }

    private function write(array $users): void
    {
        $file = $this->file();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0700, true);
        }
        file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($file, 0600);
    }

    private static function normalize(string $login): string
    {
        return strtolower(trim($login));
    }
}
