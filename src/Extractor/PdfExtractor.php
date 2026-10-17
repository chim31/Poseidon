<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Config;
use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Process;
use Poseidon\Support\Text;

/**
 * PDF : pdftotext (poppler) quand il est disponible, sinon lecteur PHP de secours
 * (flux FlateDecode + opérateurs Tj/TJ). Détecte les PDF chiffrés et borne le temps
 * d'analyse pour qu'un fichier piégé ne bloque jamais la recherche.
 */
final class PdfExtractor implements Extractor
{
    private ?string $binary;

    private Ocr $ocr;

    public function __construct(private Config $config, ?Ocr $ocr = null)
    {
        $this->ocr = $ocr ?? new Ocr($config);
        $configured = $config->get('pdftotext');
        $this->binary = is_string($configured) && $configured !== '' ? $configured : Process::which('pdftotext');
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function engine(): string
    {
        return $this->binary !== null ? 'pdftotext' : 'php';
    }

    public function extract(Source $source): Document
    {
        $document = $this->binary !== null ? $this->withPoppler($source) : $this->withPhp($source->bytes());
        // PDF scanné : presque pas de texte → OCR si Tesseract est disponible.
        if ($this->ocr->canReadPdf() && mb_strlen(trim($document->text())) < 20) {
            $lines = $this->withTempFile($source, fn (string $path): array => $this->ocr->pdf($path));
            if ($lines !== []) {
                return new Document($lines, [], ['Texte obtenu par OCR : vérifier les résultats.']);
            }
        }

        return $document;
    }

    /** @template T @param \Closure(string): T $use @return T */
    private function withTempFile(Source $source, \Closure $use): mixed
    {
        if (!$source->inMemory()) {
            return $use($source->diskPath);
        }
        $temp = tempnam(sys_get_temp_dir(), 'poseidon_pdf_');
        if ($temp === false) {
            throw new UnreadableFileException('Fichier temporaire impossible pour le PDF.');
        }
        file_put_contents($temp, $source->bytes());
        try {
            return $use($temp);
        } finally {
            @unlink($temp);
        }
    }

    private function withPoppler(Source $source): Document
    {
        $temp = null;
        $path = $source->diskPath;
        if ($source->inMemory()) {
            $temp = tempnam(sys_get_temp_dir(), 'poseidon_pdf_');
            if ($temp === false) {
                throw new UnreadableFileException('Fichier temporaire impossible pour le PDF.');
            }
            file_put_contents($temp, $source->bytes());
            $path = $temp;
        }

        try {
            $result = Process::run([$this->binary, '-layout', '-enc', 'UTF-8', '-q', $path, '-'], (int) $this->config->get('pdf_timeout', 30));
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }

        if ($result['timedOut']) {
            throw new UnreadableFileException('Analyse du PDF interrompue : délai dépassé.', UnreadableFileException::TIMEOUT);
        }
        if ($result['code'] !== 0) {
            $err = strtolower($result['stderr']);
            if (str_contains($err, 'password') || str_contains($err, 'encrypt') || $result['code'] === 3) {
                throw UnreadableFileException::protected('PDF protégé par mot de passe');
            }
            throw new UnreadableFileException('PDF illisible ou corrompu.', UnreadableFileException::CORRUPT);
        }

        $lines = [];
        foreach (explode("\f", $result['stdout']) as $page => $text) {
            foreach (preg_split('/\r\n|\n/', $text) ?: [] as $line) {
                $line = trim(preg_replace('/[ \t]{3,}/', '   ', $line) ?? $line);
                if ($line !== '') {
                    $lines[] = ['p. ' . ($page + 1), $line];
                }
            }
        }

        return new Document($lines);
    }

    /** Lecteur de secours en PHP pur : couvre les PDF texte simples. */
    public function withPhp(string $pdf): Document
    {
        if (!str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF')) {
            throw new UnreadableFileException('Ce fichier n\'est pas un PDF valide.', UnreadableFileException::CORRUPT);
        }
        if (preg_match('#/Encrypt\s+\d+\s+\d+\s+R#', $pdf)) {
            throw UnreadableFileException::protected('PDF chiffré (installe pdftotext pour les PDF protégés en écriture seulement)');
        }

        $lines = [];
        $page = 0;
        $budget = 200 * 1024 * 1024; // volume décompressé maximal par document (anti-bombe)
        preg_match_all('#<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream#s', $pdf, $streams, PREG_SET_ORDER);
        foreach ($streams as $stream) {
            $dict = $stream[1];
            $data = $stream[2];
            if (str_contains($dict, '/FlateDecode')) {
                $decoded = @zlib_decode($data, min(50 * 1024 * 1024, $budget));
                if ($decoded === false) {
                    continue;
                }
                $budget -= strlen($decoded);
                if ($budget <= 0) {
                    throw new UnreadableFileException('PDF suspect (flux décompressés démesurés) : analyse interrompue.', UnreadableFileException::LIMIT);
                }
                $data = $decoded;
            } elseif (preg_match('#/Filter#', $dict)) {
                continue; // filtres images ou non pris en charge
            }
            if (!str_contains($data, 'BT')) {
                continue;
            }
            $page++;
            foreach (self::textFromContent($data) as $line) {
                $lines[] = ['flux ' . $page, $line];
            }
        }

        return new Document($lines);
    }

    /** @return list<string> */
    private static function textFromContent(string $content): array
    {
        $out = [];
        preg_match_all('#BT(.*?)ET#s', $content, $blocks);
        foreach ($blocks[1] as $block) {
            $line = '';
            preg_match_all('#(\[(?:[^\]\\\\]|\\\\.)*\]\s*TJ|\((?:[^)\\\\]|\\\\.)*\)\s*(?:Tj|\'|")|T\*|Td|TD)#s', $block, $ops);
            foreach ($ops[0] as $op) {
                if (str_ends_with($op, 'TJ')) {
                    preg_match_all('#\(((?:[^)\\\\]|\\\\.)*)\)|(-?\d+(?:\.\d+)?)#', $op, $parts, PREG_SET_ORDER);
                    foreach ($parts as $part) {
                        if (isset($part[2]) && $part[2] !== '' && (float) $part[2] < -200) {
                            $line .= ' ';
                        } elseif ($part[1] !== '') {
                            $line .= self::unescape($part[1]);
                        }
                    }
                } elseif ($op[0] === '(') {
                    $line .= self::unescape((string) substr($op, 1, (int) strrpos($op, ')') - 1));
                } elseif (trim($line) !== '') {
                    $out[] = trim($line);
                    $line = '';
                }
            }
            if (trim($line) !== '') {
                $out[] = trim($line);
            }
        }

        return array_map([Text::class, 'toUtf8'], $out);
    }

    private static function unescape(string $s): string
    {
        return (string) preg_replace_callback('#\\\\([nrtbf()\\\\]|[0-7]{1,3})#', static function (array $m): string {
            return match ($m[1]) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f",
                '(', ')', '\\' => $m[1],
                default => chr(octdec($m[1]) & 0xFF),
            };
        }, $s);
    }
}
