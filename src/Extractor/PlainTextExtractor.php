<?php

declare(strict_types=1);

namespace Poseidon\Extractor;

use Poseidon\Filesystem\Source;
use Poseidon\Support\Text;

/**
 * Fichiers texte : TXT, MD, LOG, code source, configuration, HTML/XML...
 */
final class PlainTextExtractor implements Extractor
{
    public const EXTENSIONS = [
        'txt', 'text', 'md', 'markdown', 'rst', 'log', 'json', 'xml', 'html', 'htm', 'xhtml', 'css', 'scss',
        'js', 'mjs', 'ts', 'tsx', 'jsx', 'vue', 'php', 'py', 'rb', 'java', 'kt', 'c', 'h', 'cpp', 'hpp', 'cs',
        'go', 'rs', 'swift', 'sh', 'bash', 'zsh', 'bat', 'cmd', 'ps1', 'sql', 'yml', 'yaml', 'toml', 'ini',
        'conf', 'cfg', 'env', 'properties', 'tex', 'srt', 'vtt', 'svg', 'nfo', 'gitignore', 'dockerfile',
    ];

    public function extensions(): array
    {
        return self::EXTENSIONS;
    }

    public function extract(Source $source): Document
    {
        $data = $source->bytes();
        if (Text::looksBinary($data)) {
            return Document::empty();
        }

        return new Document(Text::lines(Text::toUtf8($data)));
    }
}
