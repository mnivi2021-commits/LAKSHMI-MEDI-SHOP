<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * A source of incoming email. Business code only sees this interface; which
 * provider an account uses is stored on the account (email_accounts.provider)
 * and its secrets come from .env (config/services.php) - never the database.
 */
interface MailProvider
{
    /** Short name shown to users, e.g. "IMAP". */
    public function name(): string;

    /** Null when the provider can be used; otherwise a plain explanation of what is missing. */
    public function unavailableReason(): ?string;

    /**
     * Messages newer than $cursor (provider-specific watermark), oldest first.
     *
     * @return array{messages: list<IncomingMail>, cursor: ?string}
     */
    public function fetch(array $account, ?string $cursor, int $limit): array;
}
