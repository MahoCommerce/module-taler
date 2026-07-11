<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

beforeEach(function (): void {
    $this->method = new Maho_Taler_Model_Method_Standard();
});

test('uses the taler method code', function (): void {
    expect($this->method->getCode())->toBe('taler');
});

test('is a redirect gateway that captures and refunds online', function (): void {
    expect($this->method->isGateway())->toBeTrue()
        ->and($this->method->canCapture())->toBeTrue()
        ->and($this->method->canRefund())->toBeTrue()
        ->and($this->method->canRefundPartialPerInvoice())->toBeTrue()
        ->and($this->method->canFetchTransactionInfo())->toBeTrue()
        ->and($this->method->isInitializeNeeded())->toBeTrue();
});

test('cannot authorize and is not usable from the admin', function (): void {
    // Taler has no authorize/void: unpaid backend orders simply expire, and
    // paying requires the customer's wallet in a browser.
    expect($this->method->canAuthorize())->toBeFalse()
        ->and($this->method->canUseInternal())->toBeFalse();
});
