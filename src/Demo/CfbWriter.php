<?php

declare(strict_types=1);

namespace Poseidon\Demo;

/**
 * Écrivain minimal de conteneur OLE (CFB v3, secteurs de 512 octets, mini-flux) :
 * sert à produire des messages .msg synthétiques pour la démonstration et les tests.
 */
final class CfbWriter
{
    private const END = 0xFFFFFFFE;
    private const FATSECT = 0xFFFFFFFD;
    private const FREE = 0xFFFFFFFF;
    private const NONE = 0xFFFFFFFF;

    /**
     * @param array<string, string|array<string, string>> $tree Nom => contenu (flux) ou tableau (dossier).
     */
    public static function build(array $tree): string
    {
        // 1. Aplatir l'arbre en entrées de répertoire.
        $entries = [['name' => 'Root Entry', 'type' => 5, 'data' => '', 'children' => []]];
        $add = static function (array $nodes, int $parent) use (&$entries, &$add): void {
            foreach ($nodes as $name => $node) {
                $id = count($entries);
                $entries[] = ['name' => (string) $name, 'type' => is_array($node) ? 1 : 2, 'data' => is_array($node) ? '' : $node, 'children' => []];
                $entries[$parent]['children'][] = $id;
                if (is_array($node)) {
                    $add($node, $id);
                }
            }
        };
        $add($tree, 0);

        // 2. Petits flux dans le mini-flux, gros flux en secteurs.
        $mini = '';
        $miniFat = [];
        $big = [];
        foreach ($entries as $id => &$entry) {
            if ($entry['type'] !== 2) {
                continue;
            }
            $size = strlen($entry['data']);
            if ($size < 4096) {
                $start = intdiv(strlen($mini), 64);
                $count = max(1, (int) ceil($size / 64));
                $mini .= str_pad($entry['data'], $count * 64, "\0");
                for ($i = 0; $i < $count; $i++) {
                    $miniFat[] = $i === $count - 1 ? self::END : $start + $i + 1;
                }
                $entry['start'] = $size === 0 ? self::END : $start;
            } else {
                $big[$id] = $entry['data'];
            }
        }
        unset($entry);

        $dirSectors = (int) ceil(count($entries) * 128 / 512);
        $miniFatSectors = (int) ceil(count($miniFat) * 4 / 512);
        $miniDataSectors = (int) ceil(strlen($mini) / 512);
        $bigSectors = array_sum(array_map(static fn (string $d): int => (int) ceil(strlen($d) / 512), $big));
        $fatSectors = 1;
        while ($fatSectors * 128 < $fatSectors + $dirSectors + $miniFatSectors + $miniDataSectors + $bigSectors) {
            $fatSectors++;
        }

        // 3. Disposition : FAT | répertoire | mini-FAT | mini-flux | gros flux.
        $fat = array_fill(0, $fatSectors * 128, self::FREE);
        $next = 0;
        $chain = static function (int $count) use (&$fat, &$next): int {
            if ($count === 0) {
                return self::END;
            }
            $start = $next;
            for ($i = 0; $i < $count; $i++) {
                $fat[$next] = $i === $count - 1 ? self::END : $next + 1;
                $next++;
            }

            return $start;
        };
        for ($i = 0; $i < $fatSectors; $i++) {
            $fat[$next++] = self::FATSECT;
        }
        $dirStart = $chain($dirSectors);
        $miniFatStart = $chain($miniFatSectors);
        $miniStart = $chain($miniDataSectors);
        $entries[0]['start'] = $miniStart;
        $entries[0]['size'] = strlen($mini);
        $bigData = '';
        foreach ($big as $id => $data) {
            $entries[$id]['start'] = $chain((int) ceil(strlen($data) / 512));
            $bigData .= str_pad($data, (int) ceil(strlen($data) / 512) * 512, "\0");
        }

        // 4. Répertoire : enfants reliés en chaîne par « droite » (arbre valide pour la lecture).
        $dir = '';
        foreach ($entries as $id => $entry) {
            $siblings = null;
            foreach ($entries as $pid => $p) {
                if (in_array($id, $p['children'], true)) {
                    $siblings = $p['children'];
                }
            }
            $right = self::NONE;
            if ($siblings !== null) {
                $pos = array_search($id, $siblings, true);
                $right = $siblings[$pos + 1] ?? self::NONE;
            }
            $name = mb_convert_encoding($entry['name'], 'UTF-16LE', 'UTF-8');
            $dir .= str_pad($name . "\0\0", 64, "\0")
                . pack('v', strlen($name) + 2)
                . chr($entry['type']) . chr(1)
                . pack('VVV', self::NONE, $right, $entry['children'][0] ?? self::NONE)
                . str_repeat("\0", 16 + 4 + 16)
                . pack('V', $entry['start'] ?? self::END)
                . pack('VV', $entry['type'] === 2 ? strlen($entry['data']) : ($entry['size'] ?? 0), 0);
        }
        $dir = str_pad($dir, $dirSectors * 512, "\0");
        for ($i = strlen($dir) / 128; $i < $dirSectors * 4; $i++) {
            // entrées vides déjà nulles ; marquer gauche/droite/enfant comme absents
            $dir = substr_replace($dir, pack('VVV', self::NONE, self::NONE, self::NONE), (int) ($i * 128 + 68), 12);
        }

        $header = self::SIGNATURE()
            . str_repeat("\0", 16)
            . pack('vv', 0x3E, 3) . pack('v', 0xFFFE) . pack('vv', 9, 6)
            . str_repeat("\0", 6)
            . pack('V', 0) . pack('V', $fatSectors) . pack('V', $dirStart) . pack('V', 0)
            . pack('V', 4096) . pack('V', $miniFatStart) . pack('V', $miniFatSectors)
            . pack('V', self::END) . pack('V', 0);
        for ($i = 0; $i < 109; $i++) {
            $header .= pack('V', $i < $fatSectors ? $i : self::FREE);
        }

        $fatData = '';
        foreach ($fat as $v) {
            $fatData .= pack('V', $v);
        }
        $miniFatData = '';
        foreach ($miniFat as $v) {
            $miniFatData .= pack('V', $v);
        }

        return $header
            . $fatData
            . $dir
            . str_pad($miniFatData, $miniFatSectors * 512, pack('V', self::FREE))
            . str_pad($mini, $miniDataSectors * 512, "\0")
            . $bigData;
    }

    /** Message Outlook minimal : objet, expéditeur, destinataire, corps, pièces jointes. */
    public static function message(string $subject, string $from, string $to, string $body, array $attachments = []): string
    {
        $u = static fn (string $s): string => mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
        $tree = [
            '__properties_version1.0' => str_repeat("\0", 32),
            '__substg1.0_0037001F' => $u($subject),
            '__substg1.0_0C1A001F' => $u($from),
            '__substg1.0_0E04001F' => $u($to),
            '__substg1.0_1000001F' => $u($body),
            '__substg1.0_007D001F' => $u("Date: Thu, 15 Oct 2026 09:30:00 +0200\r\n"),
        ];
        $i = 0;
        foreach ($attachments as $name => $data) {
            $tree[sprintf('__attach_version1.0_#%08X', $i++)] = [
                '__properties_version1.0' => str_repeat("\0", 8),
                '__substg1.0_3707001F' => $u((string) $name),
                '__substg1.0_37010102' => $data,
            ];
        }

        return self::build($tree);
    }

    private static function SIGNATURE(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }
}
