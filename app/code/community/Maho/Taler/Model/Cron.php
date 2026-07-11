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
     * finalize it (capture or cancel). Called from cron and from the
     * return-from-payment flow, so it must be a no-op for orders already
     * past pending_payment — that guard is also what makes reloading the
     * success URL and concurrent cron passes harmless.
     *
     * $logNoAction controls whether a still-unpaid order writes a log line:
     * useful on the customer-return flow where it documents *why* the
     * customer was bounced back to the cart, pure noise on the recurring
     * cron passes.
     *
     * $timeoutMs long-polls the status endpoint — the return flow uses a
     * short one to bridge the race between the wallet paying and the
     * backend registering it.
     */
    public function processPaymentStatus(Mage_Sales_Model_Order $order, bool $logNoAction = true, ?int $timeoutMs = null): void
    {
        $helper = Mage::helper('maho_taler');
        $storeId = (int) $order->getStoreId();

        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }

        $talerOrderId = (string) $payment->getAdditionalInformation('taler_order_id');

        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            // Reconciliation: a wallet can pay after we cancelled the order
            // by expiry (there is no webhook to warn us). Only reachable via
            // the return flow / manual checks — the cron scans pending only.
            if ($order->isCanceled() && $talerOrderId !== '') {
                try {
                    $response = $this->_getApi($storeId)->getOrder($talerOrderId);
                    if (($response['order_status'] ?? '') === 'paid') {
                        $helper->log(
                            "Taler: payment received for CANCELLED order #{$order->getIncrementId()} — manual reconciliation needed (refund it from the backend or reopen the order)",
                            Mage::LOG_WARNING,
                            $storeId,
                        );
                    }
                } catch (\Throwable $e) {
                    Mage::logException($e);
                }
            }
            return;
        }

        if ($talerOrderId === '') {
            $helper->log(
                "Taler: no taler_order_id on order #{$order->getIncrementId()}, skipping",
                Mage::LOG_WARNING,
                $storeId,
            );
            return;
        }

        try {
            $response = $this->_getApi($storeId)->getOrder($talerOrderId, $timeoutMs);
        } catch (Maho_Taler_Model_Api_NotFoundException) {
            // The backend no longer knows the order (deleted or expired
            // there): it can never be paid, cancel it locally. No quote
            // handling here — if the customer came back to the store,
            // successAction already gave them their cart back.
            $order->cancel()->save();
            $helper->log(
                "Taler: cancelled order #{$order->getIncrementId()} (order unknown to the backend)",
                Mage::LOG_INFO,
                $storeId,
            );
            return;
        }

        $orderStatus = (string) ($response['order_status'] ?? '');

        if ($orderStatus === 'paid') {
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

        // "unpaid" or "claimed": the backend hasn't seen the coins *yet* —
        // the customer may still have the payment page or QR code open, so
        // the order stays in pending_payment until the window has clearly
        // expired.
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

    protected function _getApi(int $storeId): Maho_Taler_Model_Api
    {
        /** @var Maho_Taler_Model_Api $api */
        $api = Mage::getModel('maho_taler/api', ['store_id' => $storeId]);
        return $api;
    }
}
