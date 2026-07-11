<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

test('not-found errors are catchable as the generic Maho exception', function (): void {
    // Cron/refund flows rely on catching the specific 404 exception while
    // generic \Throwable handlers keep working.
    expect(new Maho_Taler_Model_Api_NotFoundException('gone'))
        ->toBeInstanceOf(Mage_Core_Exception::class);
});

test('accepts the store id via constructor args like Mage::getModel passes it', function (): void {
    $api = new Maho_Taler_Model_Api(['store_id' => '3']);
    expect($api)->toBeInstanceOf(Maho_Taler_Model_Api::class)
        ->and($api->setStoreId(5))->toBe($api);
});
