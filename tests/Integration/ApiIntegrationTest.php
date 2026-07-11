<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Live tests of Maho_Taler_Model_Api against a real Taler merchant backend
 * (the public demo backend by default — see tests/Pest.php for the env vars).
 * Currency is read from the backend's /config so the suite works against any
 * backend, not just the KUDOS demo.
 */
function talerApi(): Maho_Taler_Model_Api
{
    /** @var Maho_Taler_Model_Api $api */
    $api = Mage::getModel('maho_taler/api', ['store_id' => (int) Mage::app()->getStore()->getId()]);
    return $api;
}

function talerCurrency(): string
{
    static $currency = null;
    if ($currency === null) {
        $currency = (string) talerApi()->getConfig()['currency'];
    }
    return $currency;
}

function talerOrderSpec(string $orderId, float $value): array
{
    /** @var Maho_Taler_Helper_Data $helper */
    $helper = Mage::helper('maho_taler');
    return [
        'order_id' => $orderId,
        'amount' => $helper->formatAmount(talerCurrency(), $value),
        'summary' => 'Maho_Taler integration test',
        'fulfillment_url' => 'https://example.com/taler/payment/success?order=' . $orderId,
        'products' => [
            ['description' => 'Integration test product', 'quantity' => 1, 'price' => $helper->formatAmount(talerCurrency(), $value)],
        ],
    ];
}

function talerTestOrderId(): string
{
    return 'maho-it-' . bin2hex(random_bytes(6));
}

function talerDeleteQuietly(string $orderId): void
{
    try {
        talerApi()->deleteOrder($orderId);
    } catch (\Throwable) {
        // Cleanup only — a leftover unpaid order expires on its own.
    }
}

test('the backend identifies itself as a taler-merchant and advertises a currency', function (): void {
    $config = talerApi()->getConfig();

    expect($config['name'])->toBe('taler-merchant')
        ->and($config['currency'])->toBeString()->not->toBe('')
        ->and($config['version'])->toBeString();
});

test('an order round-trips: created, read back unpaid with pay URI and status page, deleted', function (): void {
    $orderId = talerTestOrderId();

    try {
        $created = talerApi()->createOrder(talerOrderSpec($orderId, 2.50), ['d_us' => 86400000000]);
        expect($created['order_id'])->toBe($orderId)
            ->and($created['token'] ?? '')->not->toBe('');

        $status = talerApi()->getOrder($orderId);
        expect($status['order_status'])->toBe('unpaid')
            ->and($status['taler_pay_uri'] ?? '')->toStartWith('taler')
            ->and($status['order_status_url'] ?? '')->toStartWith('http');

        // The amount survives as contract terms — the same field
        // capturePaidOrder() validates before invoicing.
        /** @var Maho_Taler_Helper_Data $helper */
        $helper = Mage::helper('maho_taler');
        $termsAmount = (string) ($status['proto_contract_terms']['amount'] ?? $status['contract_terms']['amount'] ?? '');
        expect($helper->amountsMatch($termsAmount, $helper->formatAmount(talerCurrency(), 2.50)))->toBeTrue();
    } finally {
        talerDeleteQuietly($orderId);
    }

    // Deleted orders are gone for real.
    expect(fn() => talerApi()->getOrder($orderId))
        ->toThrow(Maho_Taler_Model_Api_NotFoundException::class);
});

test('reading an unknown order throws the not-found exception the cron relies on', function (): void {
    expect(fn() => talerApi()->getOrder('maho-it-does-not-exist-' . bin2hex(random_bytes(4))))
        ->toThrow(Maho_Taler_Model_Api_NotFoundException::class);
});

test('refunding an unknown order reports it as unknown to the backend', function (): void {
    /** @var Maho_Taler_Helper_Data $helper */
    $helper = Mage::helper('maho_taler');

    expect(fn() => talerApi()->refundOrder(
        'maho-it-does-not-exist-' . bin2hex(random_bytes(4)),
        $helper->formatAmount(talerCurrency(), 1.00),
        'integration test',
    ))->toThrow(Maho_Taler_Model_Api_NotFoundException::class);
});

test('refunding an unpaid order is rejected and leaves the order intact', function (): void {
    $orderId = talerTestOrderId();
    /** @var Maho_Taler_Helper_Data $helper */
    $helper = Mage::helper('maho_taler');

    try {
        talerApi()->createOrder(talerOrderSpec($orderId, 1.00), ['d_us' => 86400000000]);

        // Current backends (observed on protocol v30) answer 404 here — an
        // unpaid order has no paid contract to refund — so the client maps it
        // to the not-found exception. Either way it must throw, and the order
        // itself must survive the rejected refund.
        expect(fn() => talerApi()->refundOrder($orderId, $helper->formatAmount(talerCurrency(), 1.00), 'integration test'))
            ->toThrow(Mage_Core_Exception::class)
            ->and(talerApi()->getOrder($orderId)['order_status'])->toBe('unpaid');
    } finally {
        talerDeleteQuietly($orderId);
    }
});

test('a wrong token is rejected by the private API', function (): void {
    Mage::app()->getStore()->setConfig('maho_taler/connection/api_token', 'wrong-token-' . bin2hex(random_bytes(4)));

    expect(fn() => talerApi()->createOrder(talerOrderSpec(talerTestOrderId(), 1.00)))
        ->toThrow(Mage_Core_Exception::class);
    // The beforeEach bootstrap restores the real token for the next test.
});
