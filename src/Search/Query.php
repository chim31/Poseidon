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
 *  - regex  : la saisie est une expression régulière (PCRE).
 * En modes all/any, « des guillemets » gardent une expression groupée comme un seul terme.
 */
final class Query
{
    public const PHRASE = 'phrase';
    public const ALL = 'all';
    public const ANY = 'any';
    public const REGEX = 'regex';

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
    ) {
        $input = trim($input);
        if ($input === '') {
            throw new \InvalidArgumentException('Le filtre de recherche est vide.');
        }
        if (!in_array($mode, [self::PHRASE, self::ALL, self::ANY, self::REGEX], true)) {
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

        return $this->mode === self::ALL ? $found === count($this->terms) : $found > 0;
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
        ];
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
