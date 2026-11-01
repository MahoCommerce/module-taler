<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/*
 * Unit suite: the Maho classes load from the composer classmap, but no Mage
 * application is bootstrapped (no config, no DB). Unit tests must therefore
 * stick to logic that never calls Mage::app()/Mage::getStoreConfig().
 *
 * Integration suite: opt-in via TALER_INTEGRATION=1. Requires a Maho
 * installation in this repo (php maho install --db_engine sqlite ...) and
 * talks to a real Taler merchant backend over HTTPS:
 *
 *   TALER_BACKEND_URL  backend base URL   (default https://backend.demo.taler.net)
 *   TALER_INSTANCE     instance id        (default "sandbox"; "@root" = default instance)
 *   TALER_API_TOKEN    /private API token (default "sandbox")
 *
 * The paid-path tests also need taler-wallet-cli (see PaymentIntegrationTest).
 * The storefront and admin flows are covered by the Playwright suite in
 * tests/Browser.
 */

const TALER_INTEGRATION_WEBHOOK_SECRET = 'integration-webhook-secret';

uses()
    // Boot the Maho application outside the per-test handler snapshot:
    // Mage::app() installs a global error handler, and doing that inside a
    // test (or its beforeEach) gets the test flagged as risky.
    ->beforeAll(function (): void {
        if (getenv('TALER_INTEGRATION') !== '1') {
            return;
        }
        if (!file_exists(__DIR__ . '/../app/etc/local.xml')) {
            throw new RuntimeException(
                'No Maho installation found. Run: php maho install --db_engine sqlite ... (see .github/workflows/tests.yml)',
            );
        }
        Mage::app('admin');
    })
    ->beforeEach(function (): void {
        if (getenv('TALER_INTEGRATION') !== '1') {
            $this->markTestSkipped('Integration tests are opt-in: set TALER_INTEGRATION=1 (see tests/Pest.php).');
        }
        taler_integration_configure();
    })
    ->in('Integration');

/**
 * Point the module's store config at the backend under test. Runtime
 * setConfig keeps the DB untouched, so tests never leak configuration into
 * the installation, and each test starts from a pristine backend config.
 */
function taler_integration_configure(): void
{
    $instance = getenv('TALER_INSTANCE');
    if ($instance === false || $instance === '') {
        $instance = 'sandbox';
    } elseif ($instance === '@root') {
        $instance = '';
    }

    $store = Mage::app()->getStore();
    $store->setConfig('maho_taler/connection/backend_url', getenv('TALER_BACKEND_URL') ?: 'https://backend.demo.taler.net');
    $store->setConfig('maho_taler/connection/instance', $instance);
    $store->setConfig('maho_taler/connection/api_token', getenv('TALER_API_TOKEN') ?: 'sandbox');
    $store->setConfig('maho_taler/connection/debug', '0');
    $store->setConfig('maho_taler/connection/webhook_secret', TALER_INTEGRATION_WEBHOOK_SECRET);
}

/**
 * A minimal pending Maho order paid with Taler, as createTalerOrder() leaves
 * it after the redirect: one item, the Taler order id and amount on the payment.
 */
function taler_integration_create_order(string $currency, float $total): Mage_Sales_Model_Order
{
    $storeId = (int) Mage::app()->getStore()->getId();
    $incrementId = 'it' . bin2hex(random_bytes(5));
    $talerOrderId = $incrementId . '-' . bin2hex(random_bytes(4));

    /** @var Mage_Sales_Model_Order $order */
    $order = Mage::getModel('sales/order');
    $order->setData([
        'increment_id' => $incrementId,
        'store_id' => $storeId,
        'state' => Mage_Sales_Model_Order::STATE_PENDING_PAYMENT,
        'status' => 'pending_payment',
        'customer_is_guest' => 1,
        'customer_email' => 'taler-integration@example.com',
        'base_currency_code' => $currency,
        'order_currency_code' => $currency,
        'global_currency_code' => $currency,
        'store_currency_code' => $currency,
        'base_to_order_rate' => 1,
        'base_to_global_rate' => 1,
        'subtotal' => $total,
        'base_subtotal' => $total,
        'grand_total' => $total,
        'base_grand_total' => $total,
        'total_qty_ordered' => 1,
    ]);

    /** @var Mage_Sales_Model_Order_Item $item */
    $item = Mage::getModel('sales/order_item');
    $item->setData([
        'product_type' => 'simple',
        'sku' => 'taler-integration',
        'name' => 'Taler integration product',
        'qty_ordered' => 1,
        'price' => $total,
        'base_price' => $total,
        'original_price' => $total,
        'price_incl_tax' => $total,
        'base_price_incl_tax' => $total,
        'row_total' => $total,
        'base_row_total' => $total,
        'row_total_incl_tax' => $total,
        'base_row_total_incl_tax' => $total,
    ]);
    $order->addItem($item);

    /** @var Mage_Sales_Model_Order_Payment $payment */
    $payment = Mage::getModel('sales/order_payment');
    $payment->setMethod('taler');
    $payment->setAdditionalInformation('taler_order_id', $talerOrderId);
    $payment->setAdditionalInformation('taler_amount', Mage::helper('maho_taler')->formatAmount($currency, $total));
    $order->setPayment($payment);

    $order->save();
    return $order;
}

function taler_integration_reload_order(Mage_Sales_Model_Order $order): Mage_Sales_Model_Order
{
    /** @var Mage_Sales_Model_Order $fresh */
    $fresh = Mage::getModel('sales/order')->load($order->getId());
    return $fresh;
}

function taler_integration_taler_order_id(Mage_Sales_Model_Order $order): string
{
    $payment = $order->getPayment();
    return $payment ? (string) $payment->getAdditionalInformation('taler_order_id') : '';
}
