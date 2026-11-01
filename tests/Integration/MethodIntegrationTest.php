<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * The "Accepted Currency" setting, at checkout and when the order is created.
 */
function talerMethod(): Maho_Taler_Model_Method_Standard
{
    /** @var Maho_Taler_Model_Method_Standard $method */
    $method = Mage::getModel('maho_taler/method_standard');
    return $method;
}

function talerQuoteIn(string $currency): Mage_Sales_Model_Quote
{
    /** @var Mage_Sales_Model_Quote $quote */
    $quote = Mage::getModel('sales/quote');
    return $quote->setStoreId((int) Mage::app()->getStore()->getId())->setQuoteCurrencyCode($currency);
}

afterEach(function (): void {
    Mage::app()->getStore()->setConfig('maho_taler/connection/currency', '');
    Mage::app()->getStore()->setConfig('payment/taler/active', '0');
});

test('checkout shows GNU Taler only for the accepted currency', function (): void {
    $store = Mage::app()->getStore();
    $store->setConfig('payment/taler/active', '1');
    $store->setConfig('maho_taler/connection/currency', 'EUR');

    expect(talerMethod()->isAvailable(talerQuoteIn('EUR')))->toBeTrue()
        ->and(talerMethod()->isAvailable(talerQuoteIn('eur')))->toBeTrue()
        ->and(talerMethod()->isAvailable(talerQuoteIn('USD')))->toBeFalse();

    // Without an accepted currency, the backend checks the currency.
    $store->setConfig('maho_taler/connection/currency', '');
    expect(talerMethod()->isAvailable(talerQuoteIn('USD')))->toBeTrue();
});

test('an order in another currency is refused before the backend call', function (): void {
    Mage::app()->getStore()->setConfig('maho_taler/connection/currency', 'EUR');
    $order = taler_integration_create_order('USD', 1.00);

    expect(fn() => talerMethod()->createTalerOrder($order))
        ->toThrow(Mage_Core_Exception::class, 'The Taler backend accepts EUR, but the order is in USD.');
});
