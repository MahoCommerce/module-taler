<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

test('the webhook sends the shared secret header and only the order id', function (): void {
    $details = (new Maho_Taler_Model_Observer())->getWebhookDetails('https://shop.example.com/taler/webhook/', 'abc123');

    expect($details['event_type'])->toBe('pay')
        ->and($details['http_method'])->toBe('POST')
        ->and($details['url'])->toBe('https://shop.example.com/taler/webhook/')
        // The backend splits the header template on line breaks.
        ->and(explode("\n", $details['header_template']))->toBe([
            'X-Maho-Taler-Secret: abc123',
            'Content-Type: application/json',
        ])
        ->and($details['body_template'])->toBe('{"webhook_type":"{{webhook_type}}","order_id":"{{order_id}}"}');
});
