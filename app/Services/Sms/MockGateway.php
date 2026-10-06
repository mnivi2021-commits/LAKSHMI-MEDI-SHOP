<?php

declare(strict_types=1);

namespace App\Services\Sms;

/**
 * Test gateway: NEVER sends a real SMS. Every message is recorded as a test
 * (is_test = 1). To make failure handling visible, numbers ending in 0000 fail
 * with "number not reachable (test)"; everything else is "delivered".
 */
final class MockGateway implements SmsGateway
{
    public function key(): string
    {
        return 'mock';
    }

    public function isTest(): bool
    {
        return true;
    }

    public function send(string $mobile, string $message, array $meta): array
    {
        if (str_ends_with($mobile, '0000')) {
            return ['ok' => false, 'provider_id' => null, 'status' => 'failed', 'error' => 'Number not reachable (test gateway)',
                    'http_status' => 200, 'response' => ['result' => 'failed', 'reason' => 'unreachable']];
        }
        $id = 'MOCK-' . strtoupper(bin2hex(random_bytes(6)));
        return ['ok' => true, 'provider_id' => $id, 'status' => 'delivered', 'error' => null,
                'http_status' => 200, 'response' => ['result' => 'accepted', 'id' => $id, 'test' => true]];
    }
}
