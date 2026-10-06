<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Config;
use RuntimeException;

/**
 * IMAP over SSL (works with Gmail / Outlook / Zoho using an app password).
 * Needs PHP's imap extension (php.ini: extension=imap) and MAIL_IMAP_* in .env.
 * Reads INBOX by UID; the account's sync cursor is the last UID seen.
 * Only headers and a short plain-text preview are kept - never full bodies.
 */
final class ImapProvider implements MailProvider
{
    public function name(): string
    {
        return 'IMAP';
    }

    public function unavailableReason(): ?string
    {
        if (!function_exists('imap_open')) {
            return 'PHP imap extension is not enabled (php.ini: extension=imap, then restart Apache).';
        }
        $c = Config::get('services.mail.imap', []);
        if (($c['host'] ?? '') === '' || ($c['username'] ?? '') === '' || ($c['password'] ?? '') === '') {
            return 'MAIL_IMAP_HOST, MAIL_IMAP_USERNAME and MAIL_IMAP_PASSWORD are not set in .env.';
        }
        return null;
    }

    public function fetch(array $account, ?string $cursor, int $limit): array
    {
        if (($why = $this->unavailableReason()) !== null) {
            throw new RuntimeException($why);
        }
        $c = Config::get('services.mail.imap');
        $mailbox = '{' . $c['host'] . ':' . (int) $c['port'] . '/imap/ssl}INBOX';
        $imap = @imap_open($mailbox, $c['username'], $c['password'], OP_READONLY, 1);
        if ($imap === false) {
            $err = imap_last_error() ?: 'unknown error';
            imap_errors();
            throw new RuntimeException('Could not connect to the mail server: ' . mb_substr($err, 0, 200));
        }
        try {
            $from = (int) ($cursor ?? 0) + 1;
            $uids = imap_search($imap, 'ALL', SE_UID) ?: [];
            $uids = array_values(array_filter($uids, static fn ($u) => $u >= $from));
            sort($uids);
            $uids = array_slice($uids, 0, $limit);
            $out = [];
            $last = $cursor;
            foreach ($uids as $uid) {
                $out[] = $this->message($imap, (int) $uid);
                $last = (string) $uid;
            }
            return ['messages' => $out, 'cursor' => $last];
        } finally {
            imap_close($imap);
            imap_errors();
        }
    }

    private function message($imap, int $uid): IncomingMail
    {
        $msgNo = imap_msgno($imap, $uid);
        $h = imap_headerinfo($imap, $msgNo);
        $from = $h->from[0] ?? null;
        $fromEmail = $from ? strtolower(($from->mailbox ?? '') . '@' . ($from->host ?? '')) : 'unknown@unknown';
        $structure = imap_fetchstructure($imap, $uid, FT_UID);
        [$text, $attachments] = $this->walk($imap, $uid, $structure, '');
        $preview = trim(preg_replace('/\s+/u', ' ', $text));
        return new IncomingMail(
            providerMessageId: (string) $uid,
            fromEmail: mb_substr($fromEmail, 0, 150),
            fromName: isset($from->personal) ? mb_substr(self::decode($from->personal), 0, 150) : null,
            subject: isset($h->subject) ? mb_substr(self::decode($h->subject), 0, 500) : null,
            bodyPreview: $preview !== '' ? mb_substr($preview, 0, 1000) : null,
            receivedAt: date('Y-m-d H:i:s', isset($h->udate) ? (int) $h->udate : time()),
            hasAttachments: $attachments !== [],
            internetMessageId: isset($h->message_id) ? mb_substr(trim($h->message_id), 0, 255) : null,
            toRecipients: isset($h->toaddress) ? mb_substr(self::decode($h->toaddress), 0, 1000) : null,
            attachments: $attachments,
        );
    }

    /** @return array{0: string, 1: list<array{name: string, mime: ?string, size: ?int}>} */
    private function walk($imap, int $uid, object $part, string $section): array
    {
        $text = '';
        $att = [];
        $name = null;
        foreach (array_merge($part->dparameters ?? [], $part->parameters ?? []) as $p) {
            if (in_array(strtolower($p->attribute), ['filename', 'name'], true)) {
                $name = self::decode($p->value);
            }
        }
        if (!empty($part->parts)) {
            foreach ($part->parts as $i => $sub) {
                [$t, $a] = $this->walk($imap, $uid, $sub, $section === '' ? (string) ($i + 1) : "{$section}." . ($i + 1));
                $text = $text !== '' ? $text : $t;
                $att = array_merge($att, $a);
            }
            return [$text, $att];
        }
        if ($name !== null) {
            return ['', [['name' => mb_substr($name, 0, 255), 'mime' => strtolower(($part->subtype ?? '') !== '' ? "{$part->type}/{$part->subtype}" : ''), 'size' => $part->bytes ?? null]]];
        }
        if ((int) $part->type === TYPETEXT) {
            $raw = imap_fetchbody($imap, $uid, $section === '' ? '1' : $section, FT_UID | FT_PEEK);
            $raw = match ((int) $part->encoding) {
                ENCBASE64 => base64_decode($raw),
                ENCQUOTEDPRINTABLE => quoted_printable_decode($raw),
                default => $raw,
            };
            foreach ($part->parameters ?? [] as $p) {
                if (strtolower($p->attribute) === 'charset' && strtoupper($p->value) !== 'UTF-8') {
                    $raw = @mb_convert_encoding($raw, 'UTF-8', $p->value) ?: $raw;
                }
            }
            $text = strtolower($part->subtype ?? '') === 'html' ? html_entity_decode(strip_tags($raw)) : $raw;
            return [mb_substr($text, 0, 4000), []];
        }
        return ['', []];
    }

    private static function decode(string $s): string
    {
        return function_exists('iconv_mime_decode') ? (iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: $s) : $s;
    }
}
