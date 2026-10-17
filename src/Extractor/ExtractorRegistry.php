<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Config;
use Poseidon\Filesystem\Source;
use Poseidon\Support\Text;

/**
 * Choisit l'extracteur selon l'extension et met en cache le texte des formats coûteux
 * (PDF, Office) : une deuxième recherche sur la même arborescence est quasi instantanée.
 */
final class ExtractorRegistry
{
    /** @var array<string, Extractor> */
    private array $byExtension = [];

    private PlainTextExtractor $fallback;

    /** Formats dont l'extraction coûte assez cher pour mériter le cache. */
    private const CACHED = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'gif', 'webp', 'eml', 'msg', 'pdf', 'docx', 'docm', 'dotx', 'xlsx', 'xlsm', 'xltx', 'ods', 'odt', 'odp', 'pptx', 'pptm', 'doc', 'xls', 'ppt'];

    private Ocr $ocr;

    public function __construct(private Config $config)
    {
        $this->fallback = new PlainTextExtractor();
        $this->ocr = new Ocr($config);
        foreach ([
            $this->fallback,
            new CsvExtractor(),
            new WordExtractor(),
            new SpreadsheetExtractor(),
            new OpenDocumentExtractor(),
            new PdfExtractor($config, $this->ocr),
            new LegacyOfficeExtractor($config),
            new RtfExtractor(),
            new EmailExtractor(),
            ...($this->ocr->available() ? [new ImageExtractor($this->ocr)] : []),
        ] as $extractor) {
            foreach ($extractor->extensions() as $ext) {
                $this->byExtension[$ext] = $extractor;
            }
        }
    }

    /** @return list<string> */
    public function supportedExtensions(): array
    {
        return array_keys($this->byExtension);
    }

    public function supports(string $extension): bool
    {
        return isset($this->byExtension[strtolower($extension)]);
    }

    public function ocrAvailable(): bool
    {
        return $this->ocr->available();
    }

    public function pdfEngine(): string
    {
        $pdf = $this->byExtension['pdf'];

        return $pdf instanceof PdfExtractor ? $pdf->engine() : 'php';
    }

    /**
     * Extrait le texte. Les extensions inconnues sont lues comme du texte si elles n'ont pas l'air binaires.
     * Retourne null si le fichier n'est pas un format textuel (image, exécutable...).
     *
     * @throws \Poseidon\Filesystem\UnreadableFileException
     */
    public function extract(Source $source): ?Document
    {
        $ext = $source->extension();
        $extractor = $this->byExtension[$ext] ?? null;

        if ($extractor === null) {
            if (\Poseidon\Archive\ArchiveWalker::isContainer($source->name()) || in_array($ext, ['zip', 'jar', 'war', 'ear', '7z', 'rar', 'gz', 'tgz', 'tar', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'ico', 'mp3', 'mp4', 'avi', 'mov', 'mkv', 'exe', 'dll', 'so', 'bin', 'iso', 'gz', 'tgz', '7z', 'rar', 'woff', 'woff2', 'ttf', 'otf', 'class', 'pyc', 'o', 'a', 'db', 'sqlite'], true)) {
                return null;
            }
            $sample = $source->inArchive() ? $source->bytes() : (string) @file_get_contents($source->diskPath, false, null, 0, 8192);
            if (Text::looksBinary($sample)) {
                return null;
            }
            $extractor = $this->fallback;
        }

        $cacheFile = in_array($ext, self::CACHED, true) ? $this->cacheFile($source) : null;
        if ($cacheFile !== null && is_file($cacheFile)) {
            $cached = @unserialize((string) @gzdecode((string) file_get_contents($cacheFile)), ['allowed_classes' => [Document::class]]);
            if ($cached instanceof Document) {
                return $cached;
            }
        }

        $document = $extractor->extract($source);

        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, gzencode(serialize($document), 3), LOCK_EX);
        }

        return $document;
    }

    private function cacheFile(Source $source): ?string
    {
        $dir = (string) $this->config->get('cache_dir', '');
        if ($dir === '') {
            return null;
        }
        $key = $source->cacheKey();
        $sub = $dir . '/' . substr($key, 0, 2);
        if (!is_dir($sub) && !@mkdir($sub, 0775, true) && !is_dir($sub)) {
            return null;
        }

        return $sub . '/' . $key . '.gz';
    }
}
