<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Provider registry. Gmail API and Microsoft Graph need OAuth app registration;
 * until that is set up, those mailboxes can be read through IMAP with an app password.
 */
final class Providers
{
    public const LABELS = ['manual' => 'Manual entry', 'imap' => 'IMAP', 'gmail' => 'Gmail API', 'microsoft_graph' => 'Microsoft 365 (Graph)'];

    public static function for(string $provider): MailProvider
    {
        return match ($provider) {
            'imap'  => new ImapProvider(),
            'gmail', 'microsoft_graph' => new class ($provider) implements MailProvider {
                public function __construct(private readonly string $p) {}
                public function name(): string { return Providers::LABELS[$this->p]; }
                public function unavailableReason(): ?string
                {
                    return Providers::LABELS[$this->p] . ' sign-in (OAuth) is not set up yet. Use IMAP with an app password for this mailbox.';
                }
                public function fetch(array $account, ?string $cursor, int $limit): array
                {
                    throw new \RuntimeException((string) $this->unavailableReason());
                }
            },
            default => new ManualProvider(),
        };
    }
}
