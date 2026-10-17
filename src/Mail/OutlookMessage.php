<?php

declare(strict_types=1);

namespace Poseidon\Mail;

use Poseidon\Filesystem\UnreadableFileException;
use Poseidon\Support\Cfb;

/**
 * Message Outlook (.msg) : propriétés MAPI stockées dans un conteneur OLE.
 */
final class OutlookMessage
{
    /** @var array<string, string> */
    public array $headers = [];

    public string $body = '';

    /** @var list<array{name: string, data: string}> */
    public array $attachments = [];

    public static function parse(string $data): self
    {
        $cfb = Cfb::open($data);
        if (!$cfb->has('__properties_version1.0') && !$cfb->has('__substg1.0_0037001F') && !$cfb->has('__substg1.0_0037001E')) {
            throw new UnreadableFileException('Ce fichier .msg n\'est pas un message Outlook lisible.', UnreadableFileException::CORRUPT);
        }

        $msg = new self();
        foreach (['subject' => '0037', 'from' => '0C1A', 'to' => '0E04', 'cc' => '0E03'] as $key => $tag) {
            $value = self::text($cfb, '', $tag);
            if ($value !== null && $value !== '') {
                $msg->headers[$key] = $value;
            }
        }
        $email = self::text($cfb, '', '5D01') ?? self::text($cfb, '', '0C1F');
        if ($email !== null && $email !== '' && !str_contains($msg->headers['from'] ?? '', $email)) {
            $msg->headers['from'] = trim(($msg->headers['from'] ?? '') . ' <' . $email . '>');
        }
        $transport = self::text($cfb, '', '007D');
        if ($transport !== null && preg_match('/^Date:\s*(.+)$/mi', $transport, $m)) {
            $msg->headers['date'] = trim($m[1]);
        }

        $body = self::text($cfb, '', '1000');
        if ($body === null || trim($body) === '') {
            $html = $cfb->read('__substg1.0_10130102') ?? self::text($cfb, '', '1013');
            $body = $html === null ? '' : MimeMessage::htmlToText($html);
        }
        $msg->body = (string) $body;

        foreach ($cfb->children('') as $storage) {
            if (!str_starts_with($storage, '__attach_version1.0_')) {
                continue;
            }
            $content = $cfb->read($storage . '/__substg1.0_37010102');
            if ($content === null) {
                continue; // message joint (objet) ou pièce jointe par référence
            }
            $name = self::text($cfb, $storage, '3707') ?? self::text($cfb, $storage, '3704') ?? 'piece-jointe';
            $msg->attachments[] = ['name' => MimeMessage::safeName($name), 'data' => $content];
            if (count($msg->attachments) >= 200) {
                break;
            }
        }

        return $msg;
    }

    private static function text(Cfb $cfb, string $storage, string $tag): ?string
    {
        $prefix = $storage === '' ? '' : $storage . '/';
        $unicode = $cfb->read($prefix . '__substg1.0_' . $tag . '001F');
        if ($unicode !== null) {
            return rtrim(mb_convert_encoding($unicode, 'UTF-8', 'UTF-16LE'), "\0");
        }
        $ansi = $cfb->read($prefix . '__substg1.0_' . $tag . '001E');
        if ($ansi !== null) {
            $ansi = rtrim($ansi, "\0");

            return mb_check_encoding($ansi, 'UTF-8') ? $ansi : mb_convert_encoding($ansi, 'UTF-8', 'Windows-1252');
        }

        return null;
    }
}
