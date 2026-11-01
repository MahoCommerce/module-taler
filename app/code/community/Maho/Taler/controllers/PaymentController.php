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
     * Each poll of the store page holds a PHP worker for this long at most.
     */
    public const STATUS_POLL_TIMEOUT_MS = 10000;

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

            /** @var Maho_Taler_Helper_Data $helper */
            $helper = Mage::helper('maho_taler');
            if ($helper->getPaymentPage((int) $order->getStoreId()) === Maho_Taler_Helper_Data::PAYMENT_PAGE_STORE) {
                $this->_redirect('taler/payment/pay', ['_secure' => true]);
                return;
            }
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
     * Store payment page: QR code, wallet link and polling. Only for the
     * order of the checkout session.
     */
    #[Maho\Config\Route('/taler/payment/pay', methods: ['GET'])]
    public function payAction(): void
    {
        $order = $this->_getSessionOrder();
        if ($order === null || $this->_getTalerOrderId($order) === '') {
            $this->_redirect('checkout/cart');
            return;
        }
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $this->_redirect('taler/payment/success', ['_secure' => true]);
            return;
        }

        Mage::register('taler_order', $order);
        $this->loadLayout();
        $head = $this->getLayout()->getBlock('head');
        if ($head instanceof Mage_Page_Block_Html_Head) {
            $head->setTitle(Mage::helper('maho_taler')->__('Pay with GNU Taler'));
        }
        $this->renderLayout();
    }

    /**
     * Poll endpoint of the store payment page. It long-polls the backend for
     * a short time, finalizes the order when it is paid, and tells the page
     * when to leave.
     */
    #[Maho\Config\Route('/taler/payment/status', methods: ['GET'])]
    public function statusAction(): void
    {
        $order = $this->_getSessionOrder();
        $done = $order === null || $order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        $limit = (int) Mage::getStoreConfig('system/rate_limit/taler_payment_status');
        // Each poll holds a PHP worker, so parallel polls must not fill the pool.
        if (!$done && Mage::helper('core')->rateLimiter('taler_payment_status', $limit, 60)->attempt()) {
            // A long-poll must not keep the session locked: the customer can
            // use the store in another tab in the meantime.
            session_write_close();
            try {
                /** @var Maho_Taler_Model_Cron $cron */
                $cron = Mage::getModel('maho_taler/cron');
                $order = $cron->processPaymentStatus($order, false, self::STATUS_POLL_TIMEOUT_MS);
            } catch (\Throwable $e) {
                Mage::logException($e);
            }
            $done = $order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        }

        $this->getResponse()->setHeader('Content-Type', 'application/json', true);
        $this->getResponse()->setHeader('Cache-Control', 'no-store', true);
        $this->getResponse()->setBody((string) Mage::helper('core')->jsonEncode([
            'done' => $done,
            'redirect' => Mage::getUrl('taler/payment/success', ['_secure' => true]),
        ]));
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
                /** @var Maho_Taler_Model_Cron $cron */
                $cron = Mage::getModel('maho_taler/cron');
                $order = $cron->processPaymentStatus($order, true, 3000);
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
     * The "Cancel and return to cart" button of the store payment page. The
     * backend page has no cancel callback of its own.
     *
     * The unpaid order is deleted in the backend first, so the wallet cannot
     * pay it later. Then the status check cancels it under the order lock.
     */
    #[Maho\Config\Route('/taler/payment/cancel', methods: ['POST'])]
    public function cancelAction(): void
    {
        $order = $this->_validateFormKey() ? $this->_getSessionOrder() : null;
        if ($order === null || $this->_getTalerOrderId($order) === '') {
            $this->_redirect('checkout/cart');
            return;
        }
        if ($order->getState() !== Mage_Sales_Model_Order::STATE_PENDING_PAYMENT) {
            $this->_redirect('taler/payment/success', ['_secure' => true]);
            return;
        }

        try {
            /** @var Maho_Taler_Model_Api $api */
            $api = Mage::getModel('maho_taler/api', ['store_id' => (int) $order->getStoreId()]);
            $api->deleteOrder($this->_getTalerOrderId($order), true);
        } catch (Maho_Taler_Model_Api_NotFoundException) {
            // Already gone in the backend.
        } catch (\Throwable $e) {
            // The wallet paid while the customer clicked, or the backend failed.
            // The payment page checks the status again, and the customer can retry.
            Mage::logException($e);
            $this->_redirect('taler/payment/pay', ['_secure' => true]);
            return;
        }

        try {
            /** @var Maho_Taler_Model_Cron $cron */
            $cron = Mage::getModel('maho_taler/cron');
            $order = $cron->processPaymentStatus($order, false);
        } catch (\Throwable $e) {
            Mage::logException($e);
        }

        $session = Mage::getSingleton('checkout/session');
        $session->setQuoteId($session->getTalerQuoteId(true));
        // The status check cancelled the order under the lock. When it failed, the cron job cancels it later.
        $this->_restoreCart($order, false);
        $this->_redirect('checkout/cart');
    }

    protected function _getSessionOrder(): ?Mage_Sales_Model_Order
    {
        $incrementId = (string) Mage::getSingleton('checkout/session')->getLastRealOrderId();
        if ($incrementId === '') {
            return null;
        }
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($incrementId);
        $payment = $order->getId() ? $order->getPayment() : null;
        if (!$payment || $payment->getMethod() !== 'taler') {
            return null;
        }
        return $order;
    }

    protected function _getTalerOrderId(Mage_Sales_Model_Order $order): string
    {
        $payment = $order->getPayment();
        return $payment ? (string) $payment->getAdditionalInformation('taler_order_id') : '';
    }

    protected function _restoreCart(Mage_Sales_Model_Order $order, bool $cancelOrder = true): void
    {
        if ($cancelOrder) {
            $order->cancel()->save();
        }
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());
        if ($quote->getId()) {
            $quote->setIsActive(1)->setReservedOrderId('')->save();
            Mage::getSingleton('checkout/session')->replaceQuote($quote);
        }
    }
}
