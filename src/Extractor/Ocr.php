<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Config;
use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Process;
use Poseidon\Support\Text;

/**
 * Reconnaissance de caractères (Tesseract, optionnel) pour les PDF scannés et les images.
 * Pages PDF rendues en images par pdftoppm (poppler). Délai et nombre de pages bornés.
 */
final class Ocr
{
    private ?string $tesseract;

    private ?string $pdftoppm;

    public function __construct(private Config $config)
    {
        $enabled = $config->get('ocr.enabled', true);
        $configured = (string) ($config->get('ocr.tesseract') ?? '');
        $this->tesseract = $enabled === false ? null : ($configured !== '' ? $configured : Process::which('tesseract'));
        $this->pdftoppm = Process::which('pdftoppm');
    }

    public function available(): bool
    {
        return $this->tesseract !== null;
    }

    public function canReadPdf(): bool
    {
        return $this->tesseract !== null && $this->pdftoppm !== null;
    }

    /** @return list<array{0: string, 1: string}> */
    public function image(string $path, string $label = 'image (OCR) · L'): array
    {
        return Text::lines($this->recognize($path), $label);
    }

    /** @return list<array{0: string, 1: string}> */
    public function pdf(string $path): array
    {
        if (!$this->canReadPdf()) {
            return [];
        }
        $maxPages = (int) $this->config->get('ocr.max_pages', 30);
        $dir = sys_get_temp_dir() . '/poseidon-ocr-' . bin2hex(random_bytes(6));
        @mkdir($dir, 0700, true);
        try {
            $render = Process::run([$this->pdftoppm, '-r', '200', '-gray', '-png', '-f', '1', '-l', (string) $maxPages, $path, $dir . '/page'], 120);
            if ($render['timedOut']) {
                throw new UnreadableFileException('Rendu du PDF pour OCR interrompu : délai dépassé.', UnreadableFileException::TIMEOUT);
            }
            $pages = glob($dir . '/page-*.png') ?: [];
            natsort($pages);
            $lines = [];
            foreach (array_values($pages) as $i => $png) {
                foreach (Text::lines($this->recognize($png), '') as [, $line]) {
                    $lines[] = ['p. ' . ($i + 1) . ' (OCR)', $line];
                }
            }

            return $lines;
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function recognize(string $image): string
    {
        if ($this->tesseract === null) {
            return '';
        }
        $result = Process::run([$this->tesseract, $image, 'stdout', '-l', (string) $this->config->get('ocr.languages', 'fra+eng')], (int) $this->config->get('ocr.timeout', 60), 16 * 1024 * 1024);
        if ($result['timedOut']) {
            throw new UnreadableFileException('OCR interrompu : délai dépassé.', UnreadableFileException::TIMEOUT);
        }

        return $result['code'] === 0 ? Text::toUtf8($result['stdout']) : '';
    }
}
