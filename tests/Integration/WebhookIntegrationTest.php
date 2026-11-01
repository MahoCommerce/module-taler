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

/**
 * A cron whose backend answers every order status call with the given error.
 */
function talerCronWithBackendError(\Throwable $error): Maho_Taler_Model_Cron
{
    return new class ($error) extends Maho_Taler_Model_Cron {
        public function __construct(private readonly \Throwable $error) {}

        #[\Override]
        protected function _getApi(int $storeId): Maho_Taler_Model_Api
        {
            return new class ($this->error) extends Maho_Taler_Model_Api {
                public function __construct(private readonly \Throwable $error) {}

                #[\Override]
                public function getOrder(string $orderId, ?int $timeoutMs = null): array
                {
                    throw $this->error;
                }
            };
        }
    };
}

/**
 * Mage::logException() reads the text of the exception, so the flag shows a log write.
 */
trait TalerLogProbe
{
    public bool $logged = false;

    #[\Override]
    public function __toString(): string
    {
        $this->logged = true;
        return parent::__toString();
    }
}

test('a cancelled order that the backend no longer knows writes nothing to the exception log', function (): void {
    /** @var Mage_Sales_Model_Order_Payment $payment */
    $payment = Mage::getModel('sales/order_payment');
    $payment->setMethod('taler')->setAdditionalInformation('taler_order_id', talerTestOrderId());
    /** @var Mage_Sales_Model_Order $order */
    $order = Mage::getModel('sales/order');
    $order->setState(Mage_Sales_Model_Order::STATE_CANCELED)->setPayment($payment);

    $notFound = new class ('gone') extends Maho_Taler_Model_Api_NotFoundException {
        use TalerLogProbe;
    };
    $otherError = new class ('backend down') extends RuntimeException {
        use TalerLogProbe;
    };
    talerCronWithBackendError($notFound)->processPaymentStatus($order, false);
    talerCronWithBackendError($otherError)->processPaymentStatus($order, false);

    expect($notFound->logged)->toBeFalse()
        ->and($otherError->logged)->toBeTrue();
});
