<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

beforeEach(function (): void {
    $this->helper = new Maho_Taler_Helper_Data();
});

describe('formatAmount', function (): void {
    test('formats in Taler CURRENCY:VALUE.FF form', function (): void {
        expect($this->helper->formatAmount('KUDOS', 10.5))->toBe('KUDOS:10.50');
    });

    test('uppercases the currency code', function (): void {
        expect($this->helper->formatAmount('kudos', 5.0))->toBe('KUDOS:5.00');
    });

    test('rounds to two decimals without thousands separators', function (): void {
        expect($this->helper->formatAmount('EUR', 1234.567))->toBe('EUR:1234.57');
    });

    test('formats zero', function (): void {
        expect($this->helper->formatAmount('EUR', 0.0))->toBe('EUR:0.00');
    });
});

describe('parseAmount', function (): void {
    test('splits currency and value', function (): void {
        expect($this->helper->parseAmount('KUDOS:10.50'))
            ->toBe(['currency' => 'KUDOS', 'value' => 10.5]);
    });

    test('handles amounts without decimals', function (): void {
        expect($this->helper->parseAmount('EUR:5'))
            ->toBe(['currency' => 'EUR', 'value' => 5.0]);
    });

    test('uppercases the currency code', function (): void {
        expect($this->helper->parseAmount('eur:5.00')['currency'])->toBe('EUR');
    });

    test('defaults the value to zero when missing', function (): void {
        expect($this->helper->parseAmount('EUR')['value'])->toBe(0.0);
    });
});

describe('amountsMatch', function (): void {
    test('tolerates formatting differences', function (): void {
        expect($this->helper->amountsMatch('KUDOS:10', 'KUDOS:10.00'))->toBeTrue();
    });

    test('is case-insensitive on the currency', function (): void {
        expect($this->helper->amountsMatch('kudos:10', 'KUDOS:10.00'))->toBeTrue();
    });

    test('rejects different currencies', function (): void {
        expect($this->helper->amountsMatch('KUDOS:10.00', 'EUR:10.00'))->toBeFalse();
    });

    test('rejects different values', function (): void {
        expect($this->helper->amountsMatch('KUDOS:10.00', 'KUDOS:10.01'))->toBeFalse();
    });
});

describe('normalizeToken', function (): void {
    test('keeps the empty token empty', function (): void {
        expect($this->helper->normalizeToken(''))->toBe('');
        expect($this->helper->normalizeToken('   '))->toBe('');
    });

    test('prepends the RFC 8959 prefix when missing', function (): void {
        expect($this->helper->normalizeToken('sandbox'))->toBe('secret-token:sandbox');
    });

    test('leaves an already-prefixed token unchanged', function (): void {
        expect($this->helper->normalizeToken('secret-token:sandbox'))->toBe('secret-token:sandbox');
    });

    test('trims surrounding whitespace', function (): void {
        expect($this->helper->normalizeToken('  sandbox  '))->toBe('secret-token:sandbox');
    });
});

describe('buildApiBaseUrl', function (): void {
    test('returns the backend root for the default instance', function (): void {
        expect($this->helper->buildApiBaseUrl('https://backend.demo.taler.net', ''))
            ->toBe('https://backend.demo.taler.net');
    });

    test('appends the instances subtree for a named instance', function (): void {
        expect($this->helper->buildApiBaseUrl('https://backend.demo.taler.net', 'sandbox'))
            ->toBe('https://backend.demo.taler.net/instances/sandbox');
    });

    test('strips trailing slashes from the backend URL', function (): void {
        expect($this->helper->buildApiBaseUrl('https://backend.demo.taler.net/', 'sandbox'))
            ->toBe('https://backend.demo.taler.net/instances/sandbox');
    });

    test('url-encodes the instance id', function (): void {
        expect($this->helper->buildApiBaseUrl('https://backend.example.com', 'my shop'))
            ->toBe('https://backend.example.com/instances/my%20shop');
    });
});

describe('secretsMatch', function (): void {
    test('accepts the expected secret', function (): void {
        expect($this->helper->secretsMatch('s3cret', 's3cret'))->toBeTrue();
    });

    test('ignores whitespace around the given secret', function (): void {
        expect($this->helper->secretsMatch('s3cret', " s3cret\r\n"))->toBeTrue();
    });

    test('rejects a different secret', function (): void {
        expect($this->helper->secretsMatch('s3cret', 's3cre'))->toBeFalse();
    });

    test('rejects everything when no secret is configured', function (): void {
        expect($this->helper->secretsMatch('', ''))->toBeFalse();
    });
});

describe('getWebhookId', function (): void {
    test('is stable for one URL and valid as a backend slug', function (): void {
        $id = $this->helper->getWebhookId('https://shop.example.com/taler/webhook/');
        expect($id)->toBe($this->helper->getWebhookId('https://shop.example.com/taler/webhook/'))
            ->and($id)->toMatch('/^maho-[0-9a-f]{16}$/');
    });

    test('differs for two stores', function (): void {
        expect($this->helper->getWebhookId('https://a.example.com/taler/webhook/'))
            ->not->toBe($this->helper->getWebhookId('https://b.example.com/taler/webhook/'));
    });
});

describe('getIncrementIdFromTalerOrderId', function (): void {
    test('removes the random suffix', function (): void {
        expect($this->helper->getIncrementIdFromTalerOrderId('100000123-a1b2c3d4'))->toBe('100000123');
    });

    test('keeps dashes inside the increment id', function (): void {
        expect($this->helper->getIncrementIdFromTalerOrderId('EU-100000123-a1b2c3d4'))->toBe('EU-100000123');
    });

    test('returns empty for an id without a suffix', function (): void {
        expect($this->helper->getIncrementIdFromTalerOrderId('100000123'))->toBe('')
            ->and($this->helper->getIncrementIdFromTalerOrderId('-a1b2c3d4'))->toBe('')
            ->and($this->helper->getIncrementIdFromTalerOrderId(''))->toBe('');
    });
});
