<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Filesystem\UnreadableFileException;

/**
 * Images (PNG, JPG, TIFF, BMP, GIF, WebP) lues par OCR. Enregistré seulement si Tesseract est présent.
 */
final class ImageExtractor implements Extractor
{
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'tif', 'tiff', 'bmp', 'gif', 'webp'];

    public function __construct(private Ocr $ocr)
    {
    }

    public function extensions(): array
    {
        return self::EXTENSIONS;
    }

    public function extract(Source $source): Document
    {
        $path = $source->diskPath;
        $temp = null;
        if ($source->inMemory()) {
            $temp = tempnam(sys_get_temp_dir(), 'poseidon_img_');
            if ($temp === false) {
                throw new UnreadableFileException('Fichier temporaire impossible pour l\'image.');
            }
            file_put_contents($temp, $source->bytes());
            $path = $temp;
        }
        try {
            return new Document($this->ocr->image($path));
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }
}
