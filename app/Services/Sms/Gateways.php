<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Core\Config;
use RuntimeException;

/**
 * Picks the gateway from SMS_GATEWAY. Only the test gateway ships today; a real
 * provider (MSG91, Textlocal, Gupshup ...) is added as one class implementing
 * SmsGateway plus a line here, after the company's DLT registration is done.
 */
final class Gateways
{
    public static function current(): SmsGateway
    {
        $key = (string) Config::get('services.sms.gateway', 'mock');
        return match ($key) {
            'mock', '' => new MockGateway(),
            default => throw new RuntimeException("SMS gateway \"{$key}\" is not installed. Set SMS_GATEWAY=mock or add the provider class."),
        };
    }
}
