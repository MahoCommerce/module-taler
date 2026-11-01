<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

function talerPaymentBlockFor(string $payUri): Maho_Taler_Block_Payment
{
    /** @var Mage_Sales_Model_Order_Payment $payment */
    $payment = Mage::getModel('sales/order_payment');
    $payment->setMethod('taler')->setAdditionalInformation('taler_pay_uri', $payUri);
    /** @var Mage_Sales_Model_Order $order */
    $order = Mage::getModel('sales/order');
    $order->setPayment($payment);

    Mage::unregister('taler_order');
    Mage::register('taler_order', $order);
    return new Maho_Taler_Block_Payment();
}

afterEach(function (): void {
    Mage::unregister('taler_order');
});

test('the payment page links and draws only a taler pay URI', function (): void {
    // The template prints the URI in the href of the wallet link.
    $block = talerPaymentBlockFor('javascript:alert(1)');
    expect($block->getPayUri())->toBe('')
        ->and($block->getQrCodeSvg())->toBe('');

    $block = talerPaymentBlockFor('taler://pay/backend.example.com/order-1/');
    expect($block->getPayUri())->toBe('taler://pay/backend.example.com/order-1/')
        ->and($block->getQrCodeSvg())->toContain('<svg');
});
