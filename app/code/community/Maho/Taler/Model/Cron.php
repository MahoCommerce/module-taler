<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Model_Cron
{
    /**
     * How long an unpaid order may stay in pending_payment before the cron
     * cancels it. Wallet payments are near-instant once the customer acts,
     * but the customer may park the QR code and pay later, so keep the
     * window generous. Payments that land even later are caught by the
     * reconciliation warning in processPaymentStatus.
     */
    public const PAYMENT_EXPIRY_HOURS = 4;

    /**
     * Check pending Taler payments and update their status.
     *
     * Runs every 5 minutes. This is the safety net for customers who paid
     * but never returned to the store (closed the browser on the backend
     * payment page, paid from a phone wallet, ...). The scan window is twice
     * PAYMENT_EXPIRY_HOURS so every order gets cancelled once it expires
     * before ageing out of the window.
     */
    #[Maho\Config\CronJob('maho_taler_check_pending_payments', schedule: '*/5 * * * *')]
    public function checkPendingPayments(): void
    {
        $windowHours = self::PAYMENT_EXPIRY_HOURS * 2;
        $orders = Mage::getModel('sales/order')->getCollection()
            ->addFieldToFilter('state', Mage_Sales_Model_Order::STATE_PENDING_PAYMENT)
            ->addFieldToFilter('created_at', ['gteq' => date('Y-m-d H:i:s', strtotime("-{$windowHours} hours"))])
            ->setPageSize(50);

        $orders->getSelect()->join(
            ['payment' => Mage::getSingleton('core/resource')->getTableName('sales/order_payment')],
            'payment.parent_id = main_table.entity_id',
            [],
        );
        $orders->getSelect()->where('payment.method = ?', 'taler');

        foreach ($orders as $order) {
            try {
                // Quiet: a still-unpaid order is the expected case on every
                // 5-minute pass — logging it would flood taler.log.
                $this->processPaymentStatus($order, false);
            } catch (\Throwable $e) {
                Mage::helper('maho_taler')->log(
                    "Taler cron: error checking order #{$order->getIncrementId()}: {$e->getMessage()}",
                    Mage::LOG_ERROR,
                );
            }
        }
    }

    /**
     * Poll the merchant backend for the status of a pending order and
     * finalize it (capture or cancel). Called from cron, the webhook, the
     * store payment page and the return-from-payment flow, so several
     * processes can handle one order at the same time.
     *
     * The backend call (which can long-poll for $timeoutMs) runs without a
     * lock. The finalize step runs under a per-order lock on a freshly loaded
     * order, so a payment is captured only once. Returns that fresh order:
     * callers must use it instead of the object they passed in.
     *
     * $logNoAction controls whether a still-unpaid order writes a log line:
     * useful on the customer-return flow, noise on the recurring cron passes.
     */
    public function processPaymentStatus(Mage_Sales_Model_Order $order, bool $logNoAction = true, ?int $timeoutMs = null): Mage_Sales_Model_Order
    {
        $helper = Mage::helper('maho_taler');
        $storeId = (int) $order->getStoreId();

        $payment = $order->getPayment();
        if (!$payment) {
            return $order;
        }

        $talerOrderId = (string) $payment->getAdditionalInformation('taler_order_id');

        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $this->_warnIfPaidAfterCancel($order, $talerOrderId);
            return $order;
        }

        if ($talerOrderId === '') {
            $helper->log(
                "Taler: no taler_order_id on order #{$order->getIncrementId()}, skipping",
                Mage::LOG_WARNING,
                $storeId,
            );
            return $order;
        }

        try {
            $response = $this->_getApi($storeId)->getOrder($talerOrderId, $timeoutMs);
        } catch (Maho_Taler_Model_Api_NotFoundException) {
            $response = null;
        }

        /** @var Mage_Core_Model_Lock $lock */
        $lock = Mage::getSingleton('core/lock');
        $lockName = 'taler_order_' . $order->getId();
        if (!$lock->acquire($lockName, true)) {
            return $order;
        }

        try {
            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->load($order->getId());
            if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                return $order;
            }
            $this->_applyBackendStatus($order, $talerOrderId, $response, $logNoAction);
            return $order;
        } finally {
            $lock->release($lockName);
        }
    }

    /**
     * Capture or cancel a pending order from the backend status. A null
     * status means the backend no longer knows the order.
     */
    protected function _applyBackendStatus(Mage_Sales_Model_Order $order, string $talerOrderId, ?array $response, bool $logNoAction): void
    {
        $helper = Mage::helper('maho_taler');
        $storeId = (int) $order->getStoreId();

        if ($response === null) {
            // Deleted or expired in the backend, so it can never be paid. No
            // quote handling: successAction already gave the cart back.
            $order->cancel()->save();
            $helper->log(
                "Taler: cancelled order #{$order->getIncrementId()} (order unknown to the backend)",
                Mage::LOG_INFO,
                $storeId,
            );
            return;
        }

        $orderStatus = (string) ($response['order_status'] ?? '');

        $payment = $order->getPayment();
        if ($orderStatus === 'paid' && $payment) {
            /** @var Maho_Taler_Model_Method_Standard $method */
            $method = $payment->getMethodInstance();
            if ($method->capturePaidOrder($order, $response)) {
                $helper->log(
                    "Taler: captured payment for order #{$order->getIncrementId()} (taler order id {$talerOrderId})",
                    Mage::LOG_INFO,
                    $storeId,
                );
            }
            return;
        }

        // "unpaid" or "claimed": the customer may still have the payment page
        // or the QR code open, so keep the order until the window expires.
        $createdAt = $order->getCreatedAt();
        if ($createdAt !== null
            && strtotime($createdAt) < strtotime('-' . self::PAYMENT_EXPIRY_HOURS . ' hours')
        ) {
            $order->cancel()->save();
            $helper->log(
                "Taler: cancelled order #{$order->getIncrementId()} "
                . '(payment never arrived within ' . self::PAYMENT_EXPIRY_HOURS . ' hours)',
                Mage::LOG_INFO,
                $storeId,
            );
        } elseif ($logNoAction) {
            $helper->log(
                "Taler: no action for order #{$order->getIncrementId()} (status \"{$orderStatus}\")",
                Mage::LOG_INFO,
                $storeId,
            );
        }
    }

    /**
     * A wallet can pay after the order was cancelled by expiry, for example
     * when the webhook was not reachable. Log it for manual reconciliation.
     */
    protected function _warnIfPaidAfterCancel(Mage_Sales_Model_Order $order, string $talerOrderId): void
    {
        if (!$order->isCanceled() || $talerOrderId === '') {
            return;
        }
        $storeId = (int) $order->getStoreId();
        try {
            $response = $this->_getApi($storeId)->getOrder($talerOrderId);
            if (($response['order_status'] ?? '') === 'paid') {
                Mage::helper('maho_taler')->log(
                    "Taler: payment received for CANCELLED order #{$order->getIncrementId()}: manual reconciliation needed (refund it from the backend or reopen the order)",
                    Mage::LOG_WARNING,
                    $storeId,
                );
            }
        } catch (Maho_Taler_Model_Api_NotFoundException) {
            // The cancel button deleted it in the backend, so no wallet can pay it.
        } catch (\Throwable $e) {
            Mage::logException($e);
        }
    }

    protected function _getApi(int $storeId): Maho_Taler_Model_Api
    {
        /** @var Maho_Taler_Model_Api $api */
        $api = Mage::getModel('maho_taler/api', ['store_id' => $storeId]);
        return $api;
    }
}
