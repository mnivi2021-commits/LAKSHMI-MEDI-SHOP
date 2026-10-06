<?php

declare(strict_types=1);

namespace App\Services\Mail;

/** Default: emails are entered by staff on the Mail screen; nothing is fetched. */
final class ManualProvider implements MailProvider
{
    public function name(): string
    {
        return 'Manual entry';
    }

    public function unavailableReason(): ?string
    {
        return 'This account is set to manual entry. Add emails from the Mail screen, or switch the account to IMAP.';
    }

    public function fetch(array $account, ?string $cursor, int $limit): array
    {
        return ['messages' => [], 'cursor' => $cursor];
    }
}
