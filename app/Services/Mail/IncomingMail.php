<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** One received email, as handed over by any provider (IMAP, Gmail, Graph, manual entry). */
final class IncomingMail
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly ?string $subject,
        public readonly ?string $bodyPreview,
        public readonly string $receivedAt,            // 'Y-m-d H:i:s' (server time zone)
        public readonly bool $hasAttachments = false,
        public readonly ?string $internetMessageId = null,
        public readonly ?string $toRecipients = null,
        /** @var list<array{name: string, mime: ?string, size: ?int}> */
        public readonly array $attachments = [],
    ) {}
}
