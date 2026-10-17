<?php

declare(strict_types=1);

namespace Poseidon\Search;

/**
 * Filtre de recherche : un mot, une phrase, plusieurs mots, ou une expression régulière.
 *
 * Modes :
 *  - phrase : la saisie est cherchée en bloc, telle quelle ;
 *  - all    : la saisie est découpée en termes, tous doivent être présents (ET) ;
 *  - any    : au moins un des termes doit être présent (OU) ;
 *  - regex  : la saisie est une expression régulière (PCRE) ;
 *  - near   : tous les termes, à moins de $distance mots les uns des autres (même sur plusieurs lignes).
 * En modes all/any/near, « des guillemets » gardent une expression groupée comme un seul terme.
 * $fuzzy : tolérance aux fautes de frappe (mots proches, distance de Levenshtein 1 ou 2).
 */
final class Query
{
    public const PHRASE = 'phrase';
    public const ALL = 'all';
    public const ANY = 'any';
    public const REGEX = 'regex';
    public const NEAR = 'near';

    /** @var list<string> */
    public readonly array $terms;

    /** @var list<string> Motif PCRE par terme. */
    public readonly array $patterns;

    /** Lettres et leurs variantes accentuées (recherche insensible aux accents). */
    private const ACCENTS = [
        'a' => 'aàáâãäåā', 'c' => 'cçćč', 'e' => 'eèéêëēė', 'i' => 'iìíîïī', 'n' => 'nñń',
        'o' => 'oòóôõöøō', 'u' => 'uùúûüū', 'y' => 'yýÿ', 's' => 'sšś', 'z' => 'zžź',
    ];

    public function __construct(
        public readonly string $input,
        public readonly string $mode = self::ALL,
        public readonly bool $caseSensitive = false,
        public readonly bool $wholeWord = false,
        public readonly bool $ignoreAccents = true,
        public readonly int $distance = 5,
        public readonly bool $fuzzy = false,
    ) {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Le filtre de recherche est vide.');
        }
        if (!in_array($mode, [self::PHRASE, self::ALL, self::ANY, self::REGEX, self::NEAR], true)) {
            throw new \InvalidArgumentException('Mode de recherche inconnu : ' . $mode);
        }

        $this->terms = match ($mode) {
            self::PHRASE, self::REGEX => [$input],
            default => self::split($input),
        };
        $this->patterns = array_map(fn (string $t): string => $this->compile($t), $this->terms);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['q'] ?? $data['query'] ?? ''),
            (string) ($data['mode'] ?? self::ALL),
            filter_var($data['caseSensitive'] ?? false, FILTER_VALIDATE_BOOL),
            filter_var($data['wholeWord'] ?? false, FILTER_VALIDATE_BOOL),
            filter_var($data['ignoreAccents'] ?? true, FILTER_VALIDATE_BOOL),
            max(0, min(100, (int) ($data['distance'] ?? 5))),
            filter_var($data['fuzzy'] ?? false, FILTER_VALIDATE_BOOL),
        );
    }

    /** @return list<string> */
    public static function split(string $input): array
    {
        preg_match_all('/"([^"]+)"|«\s*([^»]+?)\s*»|(\S+)/u', $input, $m, PREG_SET_ORDER);
        $terms = [];
        foreach ($m as $match) {
            $term = trim($match[1] !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')));
            // Retire la ponctuation collée aux mots (« contrat, » => « contrat »).
            if (!str_contains($term, ' ')) {
                $term = preg_replace('/^[\p{P}]+|[\p{P}]+$/u', '', $term) ?? $term;
            }
            if ($term !== '' && !in_array($term, $terms, true)) {
                $terms[] = $term;
            }
        }

        return $terms === [] ? [trim($input)] : $terms;
    }

    public function isMatch(array $counts): bool
    {
        $found = count(array_filter($counts, static fn (int $c): bool => $c > 0));

        return in_array($this->mode, [self::ALL, self::NEAR], true) ? $found === count($this->terms) : $found > 0;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'q' => $this->input,
            'mode' => $this->mode,
            'terms' => $this->terms,
            'caseSensitive' => $this->caseSensitive,
            'wholeWord' => $this->wholeWord,
            'ignoreAccents' => $this->ignoreAccents,
            'distance' => $this->distance,
            'fuzzy' => $this->fuzzy,
        ];
    }

    /** Ce terme est-il cherché par similarité (recherche floue) plutôt que par motif exact ? */
    public function isFuzzy(int $term): bool
    {
        return $this->fuzzy && $this->mode !== self::REGEX && preg_match('/^[\p{L}\p{N}_]{3,}$/u', $this->terms[$term]) === 1;
    }

    /**
     * Positions (octets) des correspondances d'un terme dans un texte.
     *
     * @return list<array{0: int, 1: int}> [position, longueur] en octets
     */
    public function find(string $text, int $term): array
    {
        $out = [];
        if ($this->isFuzzy($term)) {
            $target = self::fold($this->terms[$term]);
            $max = mb_strlen($target) >= 8 ? 2 : (mb_strlen($target) >= 4 ? 1 : 0);
            if (preg_match_all('/[\p{L}\p{N}_]+/u', $text, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($m[0] as [$word, $offset]) {
                    $folded = self::fold($word);
                    if (abs(mb_strlen($folded) - mb_strlen($target)) <= $max && self::typoDistance($folded, $target, $max) <= $max) {
                        $out[] = [$offset, strlen($word)];
                    }
                }
            }

            return $out;
        }
        if (preg_match_all($this->patterns[$term], $text, $m, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($m[0] as [$value, $offset]) {
                if ($value !== '') {
                    $out[] = [$offset, strlen($value)];
                }
            }
        }

        return $out;
    }

    public function count(string $text, int $term): int
    {
        return $this->isFuzzy($term) ? count($this->find($text, $term)) : (int) preg_match_all($this->patterns[$term], $text);
    }

    /**
     * Distance de fautes de frappe (Damerau-Levenshtein restreinte) : une inversion de deux lettres
     * voisines compte pour une faute. Arrêt anticipé au-delà de $max.
     */
    public static function typoDistance(string $a, string $b, int $max = PHP_INT_MAX): int
    {
        $x = mb_str_split($a);
        $y = mb_str_split($b);
        $n = count($x);
        $m = count($y);
        $prev2 = [];
        $prev = range(0, $m);
        for ($i = 1; $i <= $n; $i++) {
            $cur = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $m; $j++) {
                $cost = $x[$i - 1] === $y[$j - 1] ? 0 : 1;
                $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $x[$i - 1] === $y[$j - 2] && $x[$i - 2] === $y[$j - 1]) {
                    $cur[$j] = min($cur[$j], $prev2[$j - 2] + 1);
                }
                $rowMin = min($rowMin, $cur[$j]);
            }
            if ($rowMin > $max) {
                return $max + 1;
            }
            $prev2 = $prev;
            $prev = $cur;
        }

        return $prev[$m];
    }

    /** Minuscules sans accents, pour comparer des mots. */
    public static function fold(string $word): string
    {
        $word = mb_strtolower($word);
        if (class_exists(\Transliterator::class)) {
            $t = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
            if ($t !== null) {
                return (string) $t->transliterate($word);
            }
        }

        return strtr($word, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ÿ' => 'y']);
    }

    private function compile(string $term): string
    {
        $flags = 'u' . ($this->caseSensitive ? '' : 'i');

        if ($this->mode === self::REGEX) {
            $pattern = '~' . str_replace('~', '\~', $term) . '~' . $flags;
            set_error_handler(static fn (): bool => true);
            $ok = preg_match($pattern, '');
            restore_error_handler();
            if ($ok === false) {
                throw new \InvalidArgumentException('Expression régulière invalide : ' . $term);
            }

            return $pattern;
        }

        $body = '';
        foreach (mb_str_split($term) as $char) {
            $body .= $this->charPattern($char);
        }
        // Espaces multiples / retours à la ligne tolérés dans une phrase.
        $body = preg_replace('/(\\\\ |\s)+/u', '\s+', $body) ?? $body;
        if ($this->wholeWord) {
            $body = '(?<![\p{L}\p{N}_])' . $body . '(?![\p{L}\p{N}_])';
        }

        return '~' . $body . '~' . $flags;
    }

    private function charPattern(string $char): string
    {
        if (!$this->ignoreAccents) {
            return preg_quote($char, '~');
        }
        $base = self::baseLetter($char);
        if ($base !== null && isset(self::ACCENTS[$base])) {
            $variants = self::ACCENTS[$base];
            if (!$this->caseSensitive || $char === mb_strtolower($char)) {
                return '[' . $variants . ']';
            }

            return '[' . mb_strtoupper($variants) . ']';
        }

        return preg_quote($char, '~');
    }

    private static function baseLetter(string $char): ?string
    {
        $lower = mb_strtolower($char);
        foreach (self::ACCENTS as $base => $variants) {
            if (str_contains($variants, $lower)) {
                return $base;
            }
        }

        return null;
    }
}
