<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_PaymentController extends Mage_Core_Controller_Front_Action
{
    /**
     * Create the order in the Taler merchant backend and redirect the
     * customer to the backend-hosted payment page (QR code / wallet handoff).
     */
    #[Maho\Config\Route('/taler/payment/redirect')]
    public function redirectAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $orderIncrementId = $session->getLastRealOrderId();

        if (!$orderIncrementId) {
            $this->_redirect('checkout/cart');
            return;
        }

        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $this->_redirect('checkout/cart');
            return;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            /** @var Maho_Taler_Model_Method_Standard $method */
            $method = $payment->getMethodInstance();
            $redirectUrl = $method->createTalerOrder($order);

            $session->setTalerQuoteId($session->getQuoteId());
            $session->unsQuoteId();

            $this->getResponse()->setRedirect($redirectUrl);
        } catch (\Throwable $e) {
            Mage::logException($e);
            Mage::getSingleton('core/session')->addError(
                Mage::helper('maho_taler')->__('Unable to initialize payment. Please try again.'),
            );
            $this->_restoreCart($order);
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * The order's fulfillment URL: the backend payment page redirects the
     * browser here once the wallet has paid.
     *
     * The URL alone proves nothing — anyone can open it — so the order is
     * finalized purely from a server-side status check against the backend's
     * /private API. The ?order= fallback exists because the payment can be
     * completed on a different device (phone wallet after a QR scan), in
     * which case there is no checkout session to resolve the order from.
     */
    #[Maho\Config\Route('/taler/payment/success')]
    public function successAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getTalerQuoteId(true));

        $orderIncrementId = (string) $session->getLastRealOrderId();
        $sessionOwnsOrder = $orderIncrementId !== '';
        if (!$sessionOwnsOrder) {
            $orderIncrementId = (string) $this->getRequest()->getParam('order', '');
        }

        $order = null;
        if ($orderIncrementId !== '') {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if (!$order->getId()) {
                $order = null;
            }
        }

        // Cross-device path: only accept orders this module created.
        if ($order !== null && !$sessionOwnsOrder) {
            $payment = $order->getPayment();
            if (!$payment
                || $payment->getMethod() !== 'taler'
                || !$payment->getAdditionalInformation('taler_order_id')
            ) {
                $order = null;
            }
        }

        if ($order !== null) {
            try {
                // Short long-poll bridges the race between the wallet's pay
                // and the backend marking the order paid.
                Mage::getModel('maho_taler/cron')->processPaymentStatus($order, true, 3000);
            } catch (\Throwable $e) {
                Mage::logException($e);
            }

            if ($order->isCanceled()) {
                if ($sessionOwnsOrder) {
                    $this->_restoreCart($order);
                }
                Mage::getSingleton('core/session')->addError(
                    Mage::helper('maho_taler')->__('Payment was not completed.'),
                );
                $this->_redirect('checkout/cart');
                return;
            }

            // Still unpaid: the backend hasn't seen the coins *yet*. Leave the
            // order in pending_payment for the cron to finalize or expire —
            // cancelling here would strand a payment that may be in flight,
            // and restoring the cart would invite a duplicate order.
            if ($order->getState() === Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
                Mage::getSingleton('core/session')->addNotice(
                    Mage::helper('maho_taler')->__('Your payment has not been confirmed yet. If you completed the payment, your order will be processed automatically as soon as the Taler backend confirms it.'),
                );
                $this->_redirect('checkout/cart');
                return;
            }
        }

        if ($sessionOwnsOrder) {
            $session->getQuote()->setIsActive(0)->save();
        }
        $this->_redirect('checkout/onepage/success', ['_secure' => true]);
    }

    /**
     * Manual bail-out from the payment page ("back to store" style links).
     * The backend page has no cancel callback of its own, so this is only
     * reachable through links we render ourselves.
     */
    #[Maho\Config\Route('/taler/payment/cancel')]
    public function cancelAction(): void
    {
        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getTalerQuoteId(true));

        $orderIncrementId = $session->getLastRealOrderId();
        if ($orderIncrementId) {
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if ($order->getId()) {
                $this->_restoreCart($order);
            }
        }

        $this->_redirect('checkout/cart');
    }

    protected function _restoreCart(Mage_Sales_Model_Order $order): void
    {
        $order->cancel()->save();
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId('')->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }
}
