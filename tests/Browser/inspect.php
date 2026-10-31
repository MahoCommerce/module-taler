<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Lets the Playwright test read Maho state as JSON.
 *
 * Usage: php inspect.php product <sku>
 *        php inspect.php order <increment id>
 *        php inspect.php config <path>=<value>
 */

require __DIR__ . '/../../vendor/autoload.php';

Mage::app('admin');

[, $command, $argument] = $_SERVER['argv'] + [null, '', ''];

if ($command === 'product') {
    $productId = Mage::getModel('catalog/product')->getIdBySku((string) $argument);
    if (!$productId) {
        fwrite(STDERR, "Product not found\n");
        exit(1);
    }
    echo json_encode(['id' => (int) $productId], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ($command === 'config') {
    // The storefront runs in another process, so write the database and flush the cache.
    [$path, $value] = explode('=', (string) $argument, 2) + ['', ''];
    Mage::getModel('core/config')->saveConfig($path, $value);
    Mage::app()->getCache()->flush();
    echo json_encode([$path => $value], JSON_THROW_ON_ERROR) . "\n";
    exit(0);
}

if ($command !== 'order') {
    fwrite(STDERR, "Usage: php inspect.php product <sku> | order <increment id> | config <path>=<value>\n");
    exit(1);
}

$order = Mage::getModel('sales/order')->loadByIncrementId((string) $argument);
if (!$order->getId()) {
    fwrite(STDERR, "Order not found\n");
    exit(1);
}

$payment = $order->getPayment();
$creditmemos = $order->getCreditmemosCollection();
$talerOrderId = $payment ? (string) $payment->getAdditionalInformation('taler_order_id') : '';

// The backend's own view of the order, so refunds are checked where the
// money actually moves and not only in Maho's totals.
$backend = null;
if ($talerOrderId !== '') {
    /** @var Maho_Taler_Model_Api $api */
    $api = Mage::getModel('maho_taler/api', ['store_id' => (int) $order->getStoreId()]);
    $status = $api->getOrder($talerOrderId);
    $backend = [
        'order_status' => $status['order_status'] ?? null,
        'refunded' => $status['refunded'] ?? null,
        'refund_amount' => $status['refund_amount'] ?? null,
    ];
}

echo json_encode([
    'id' => (int) $order->getId(),
    'state' => $order->getState(),
    'status' => $order->getStatus(),
    'currency' => $order->getOrderCurrencyCode(),
    'grand_total' => (float) $order->getGrandTotal(),
    'total_paid' => (float) $order->getTotalPaid(),
    'total_refunded' => (float) $order->getTotalRefunded(),
    'invoices' => $order->getInvoiceCollection()->count(),
    'creditmemos' => $creditmemos ? $creditmemos->count() : 0,
    'payment_method' => $payment ? $payment->getMethod() : null,
    'taler_order_id' => $talerOrderId !== '' ? $talerOrderId : null,
    'taler_amount' => $payment ? $payment->getAdditionalInformation('taler_amount') : null,
    'backend' => $backend,
], JSON_THROW_ON_ERROR) . "\n";
