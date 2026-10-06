<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * An SMS provider. Business code only uses this interface; the provider is
 * chosen by SMS_GATEWAY in .env (config/services.php). API keys never reach
 * the database or the logs.
 */
interface SmsGateway
{
    /** Key stored on each message, e.g. "mock". */
    public function key(): string;

    /** True when nothing is really sent (test mode). */
    public function isTest(): bool;

    /**
     * Send one message.
     *
     * @return array{ok: bool, provider_id: ?string, status: string, error: ?string, http_status: ?int, response: array<string, mixed>}
     *         status: 'sent' | 'delivered' | 'failed'
     */
    public function send(string $mobile, string $message, array $meta): array;
}
