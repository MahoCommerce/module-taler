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
