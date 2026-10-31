<?php

/**
 * Handles "pay" webhook calls from the Taler merchant backend.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Model_Webhook
{
    public const HTTP_OK = 200;

    /**
     * The backend never retries a 400, but it retries every other error
     * status (each hour by default), with no end. So a call that can never
     * succeed (bad body, order of another store, wrong secret) gets a 400.
     */
    public const HTTP_REJECTED = 400;

    /**
     * Temporary failure: the backend tries again in one hour. The cron job
     * finalizes the order earlier in most cases.
     */
    public const HTTP_RETRY = 503;

    /**
     * The body only tells which order to check. The payment status always
     * comes from the backend /private API, never from the body.
     */
    public function handle(#[\SensitiveParameter] string $secret, string $body): int
    {
        $helper = Mage::helper('maho_taler');

        try {
            $data = Mage::helper('core')->jsonDecode($body);
        } catch (\JsonException) {
            $helper->log('Taler webhook: invalid JSON body', Mage::LOG_WARNING);
            return self::HTTP_REJECTED;
        }

        $talerOrderId = is_array($data) && is_string($data['order_id'] ?? null) ? $data['order_id'] : '';
        $order = $this->_findOrder($talerOrderId);
        if ($order === null) {
            $helper->log("Taler webhook: no order for taler order id \"{$talerOrderId}\"", Mage::LOG_INFO);
            return self::HTTP_REJECTED;
        }

        $storeId = (int) $order->getStoreId();
        if (!$helper->isValidWebhookSecret($secret, $storeId)) {
            $helper->log(
                "Taler webhook: wrong secret for order #{$order->getIncrementId()}",
                Mage::LOG_WARNING,
                $storeId,
            );
            return self::HTTP_REJECTED;
        }

        try {
            /** @var Maho_Taler_Model_Cron $cron */
            $cron = Mage::getModel('maho_taler/cron');
            $cron->processPaymentStatus($order);
        } catch (\Throwable $e) {
            Mage::logException($e);
            return self::HTTP_RETRY;
        }

        return self::HTTP_OK;
    }

    protected function _findOrder(string $talerOrderId): ?Mage_Sales_Model_Order
    {
        $incrementId = Mage::helper('maho_taler')->getIncrementIdFromTalerOrderId($talerOrderId);
        if ($incrementId === '') {
            return null;
        }

        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        $payment = $order->getId() ? $order->getPayment() : null;
        if (!$payment
            || $payment->getMethod() !== 'taler'
            || (string) $payment->getAdditionalInformation('taler_order_id') !== $talerOrderId
        ) {
            return null;
        }

        return $order;
    }
}
