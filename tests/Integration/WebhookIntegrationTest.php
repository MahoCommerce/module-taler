<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Webhook calls against real backend orders. The paid case needs a wallet
 * and is in PaymentIntegrationTest.
 */
function talerWebhook(string $secret, string $body): int
{
    /** @var Maho_Taler_Model_Webhook $webhook */
    $webhook = Mage::getModel('maho_taler/webhook');
    return $webhook->handle($secret, $body);
}

function talerWebhookBody(string $talerOrderId): string
{
    return (string) json_encode(['webhook_type' => 'pay', 'order_id' => $talerOrderId]);
}

/**
 * A Maho order and the backend order it was created with, unpaid.
 */
function talerPendingOrderPair(float $total = 1.00): Mage_Sales_Model_Order
{
    $order = taler_integration_create_order(talerCurrency(), $total);
    $talerOrderId = taler_integration_taler_order_id($order);
    talerApi()->createOrder(talerOrderSpec($talerOrderId, $total));
    return $order;
}

test('a body that is not JSON is rejected without a retry', function (): void {
    expect(talerWebhook(TALER_INTEGRATION_WEBHOOK_SECRET, 'not json'))->toBe(Maho_Taler_Model_Webhook::HTTP_REJECTED);
});

test('an order of another store on the same instance is rejected without a retry', function (): void {
    // The other store can use the same increment id, with another random suffix.
    $order = talerPendingOrderPair();
    try {
        expect(talerWebhook(TALER_INTEGRATION_WEBHOOK_SECRET, talerWebhookBody($order->getIncrementId() . '-00000000')))
            ->toBe(Maho_Taler_Model_Webhook::HTTP_REJECTED);
    } finally {
        talerDeleteQuietly(taler_integration_taler_order_id($order));
    }
});

test('a wrong secret is rejected and leaves the order pending', function (): void {
    $order = talerPendingOrderPair();
    try {
        expect(talerWebhook('wrong', talerWebhookBody(taler_integration_taler_order_id($order))))
            ->toBe(Maho_Taler_Model_Webhook::HTTP_REJECTED)
            ->and(taler_integration_reload_order($order)->getState())->toBe(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
    } finally {
        talerDeleteQuietly(taler_integration_taler_order_id($order));
    }
});

test('a valid call for an unpaid order checks the backend and keeps the order pending', function (): void {
    $order = talerPendingOrderPair();
    try {
        // The body says "pay", but only the backend status counts.
        expect(talerWebhook(TALER_INTEGRATION_WEBHOOK_SECRET, talerWebhookBody(taler_integration_taler_order_id($order))))
            ->toBe(Maho_Taler_Model_Webhook::HTTP_OK)
            ->and(taler_integration_reload_order($order)->getState())->toBe(Mage_Sales_Model_Order::STATE_PENDING_PAYMENT);
    } finally {
        talerDeleteQuietly(taler_integration_taler_order_id($order));
    }
});

test('an order that the backend no longer knows is cancelled', function (): void {
    $order = talerPendingOrderPair();
    talerApi()->deleteOrder(taler_integration_taler_order_id($order));

    expect(talerWebhook(TALER_INTEGRATION_WEBHOOK_SECRET, talerWebhookBody(taler_integration_taler_order_id($order))))
        ->toBe(Maho_Taler_Model_Webhook::HTTP_OK)
        ->and(taler_integration_reload_order($order)->getState())->toBe(Mage_Sales_Model_Order::STATE_CANCELED);
});

test('processPaymentStatus returns the fresh order and leaves a finished order alone', function (): void {
    $order = talerPendingOrderPair();
    talerApi()->deleteOrder(taler_integration_taler_order_id($order));

    /** @var Maho_Taler_Model_Cron $cron */
    $cron = Mage::getModel('maho_taler/cron');
    $stale = taler_integration_reload_order($order);
    $first = $cron->processPaymentStatus($order, false);
    // The stale copy still says pending_payment: the lock step loads the order again.
    $second = $cron->processPaymentStatus($stale, false);

    expect($first->getState())->toBe(Mage_Sales_Model_Order::STATE_CANCELED)
        ->and($second->getState())->toBe(Mage_Sales_Model_Order::STATE_CANCELED)
        ->and($second->getStatusHistoryCollection()->count())->toBe($first->getStatusHistoryCollection()->count());
});
