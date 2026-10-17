<?php

declare(strict_types=1);

namespace Poseidon\Mail;

use Poseidon\Support\Text;

/**
 * Courriel MIME (.eml) : en-têtes décodés, corps texte (ou HTML converti) et pièces jointes.
 */
final class MimeMessage
{
    private const MAX_PARTS = 500;
    private const MAX_DEPTH = 10;

    /** @var array<string, string> En-têtes principaux décodés (subject, from, to, cc, date). */
    public array $headers = [];

    public string $body = '';

    /** @var list<array{name: string, data: string}> */
    public array $attachments = [];

    private string $html = '';

    private int $parts = 0;

    public static function parse(string $raw): self
    {
        $message = new self();
        [$headers, $body] = self::split($raw);
        foreach (['subject', 'from', 'to', 'cc', 'date'] as $name) {
            if (isset($headers[$name])) {
                $message->headers[$name] = self::decodeHeader($headers[$name]);
            }
        }
        $message->walk($headers, $body, 0);
        if (trim($message->body) === '' && $message->html !== '') {
            $message->body = self::htmlToText($message->html);
        }

        return $message;
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function split(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $pos = strpos($raw, "\n\n");
        $head = $pos === false ? $raw : substr($raw, 0, $pos);
        $body = $pos === false ? '' : substr($raw, $pos + 2);
        $head = (string) preg_replace("/\n[ \t]+/", ' ', $head); // dépliage des en-têtes
        $headers = [];
        foreach (explode("\n", $head) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                $key = strtolower($m[1]);
                $headers[$key] = isset($headers[$key]) && $key === 'received' ? $headers[$key] : $m[2];
            }
        }

        return [$headers, $body];
    }

    /** @param array<string, string> $headers */
    private function walk(array $headers, string $body, int $depth): void
    {
        if (++$this->parts > self::MAX_PARTS || $depth > self::MAX_DEPTH) {
            return;
        }
        [$type, $params] = self::parseParams($headers['content-type'] ?? 'text/plain');
        [$disposition, $dparams] = self::parseParams($headers['content-disposition'] ?? '');
        $filename = $dparams['filename'] ?? $params['name'] ?? '';

        if (str_starts_with($type, 'multipart/') && isset($params['boundary'])) {
            foreach (self::multipart($body, $params['boundary']) as $part) {
                [$h, $b] = self::split($part);
                $this->walk($h, $b, $depth + 1);
            }

            return;
        }

        $data = self::decodeBody($body, strtolower($headers['content-transfer-encoding'] ?? ''));

        if ($type === 'message/rfc822') {
            $inner = self::parse($data);
            $name = $filename !== '' ? $filename : (($inner->headers['subject'] ?? 'message') . '.eml');
            $this->attachments[] = ['name' => self::safeName($name), 'data' => $data];

            return;
        }

        $isAttachment = $disposition === 'attachment' || ($filename !== '' && !in_array($type, ['text/plain', 'text/html'], true)) || ($filename !== '' && $disposition !== 'inline');
        if ($isAttachment) {
            $this->attachments[] = ['name' => self::safeName($filename !== '' ? $filename : 'piece-jointe'), 'data' => $data];

            return;
        }
        if ($type === 'text/plain') {
            $this->body .= ($this->body === '' ? '' : "\n") . self::toUtf8($data, $params['charset'] ?? '');
        } elseif ($type === 'text/html') {
            $this->html .= self::toUtf8($data, $params['charset'] ?? '');
        }
    }

    /** @return list<string> */
    private static function multipart(string $body, string $boundary): array
    {
        $parts = preg_split('/^--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*$/m', $body) ?: [];
        array_shift($parts); // préambule

        return array_values(array_filter(array_map(static fn (string $p): string => ltrim($p, "\n"), $parts), static fn (string $p): bool => trim($p) !== ''));
    }

    /** @return array{0: string, 1: array<string, string>} */
    private static function parseParams(string $value): array
    {
        $pieces = preg_split('/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $value) ?: [];
        $main = strtolower(trim((string) array_shift($pieces)));
        $params = [];
        $continued = [];
        foreach ($pieces as $piece) {
            if (!preg_match('/^\s*([^=\s]+)\s*=\s*(.*)$/s', $piece, $m)) {
                continue;
            }
            $key = strtolower($m[1]);
            $val = trim(trim($m[2]), '"');
            // RFC 2231 : filename*=UTF-8''nom%C3%A9 et filename*0*=…
            if (preg_match('/^([^*]+)\*(\d+)?\*?$/', $key, $k)) {
                if (preg_match("/^([\\w-]+)'[^']*'(.*)$/", $val, $enc)) {
                    $val = self::toUtf8(rawurldecode($enc[2]), $enc[1]);
                } elseif (str_ends_with($key, '*')) {
                    $val = rawurldecode($val);
                }
                $continued[$k[1]][(int) ($k[2] ?? 0)] = $val;
                continue;
            }
            $params[$key] = self::decodeHeader($val);
        }
        foreach ($continued as $key => $chunks) {
            ksort($chunks);
            $params[$key] = implode('', $chunks);
        }

        return [$main, $params];
    }

    private static function decodeBody(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64' => (string) base64_decode((string) preg_replace('/\s+/', '', $body), false),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };
    }

    public static function decodeHeader(string $value): string
    {
        if (!str_contains($value, '=?')) {
            return Text::toUtf8(trim($value));
        }
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return trim($decoded === false ? mb_decode_mimeheader($value) : $decoded);
    }

    private static function toUtf8(string $data, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset !== '' && $charset !== 'UTF-8' && in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
            return mb_convert_encoding($data, 'UTF-8', $charset);
        }

        return Text::toUtf8($data);
    }

    public static function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|head)\b.*?</\1>#is', '', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/tr|/li|/h[1-6])\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace("/\n{3,}/", "\n\n", (string) preg_replace('/[ \t]+/', ' ', $text)));
    }

    public static function safeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '_', $name));

        return $name === '' || $name === '.' || $name === '..' ? 'piece-jointe' : mb_substr($name, 0, 180);
    }
}
