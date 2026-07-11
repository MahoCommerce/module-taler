<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Model_Method_Standard extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'taler';

    protected $_formBlockType = 'maho_taler/form';
    protected $_infoBlockType = 'maho_taler/info';

    protected $_isGateway = true;
    protected $_canAuthorize = false;
    protected $_canCapture = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = false;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_isInitializeNeeded = true;
    protected $_canFetchTransactionInfo = true;

    protected ?Maho_Taler_Model_Api $_api = null;

    /**
     * No currency check here: the currency a backend accepts is only known
     * from its /config endpoint, which we don't want to hit on every checkout
     * render. A mismatch fails cleanly at redirect time (the backend rejects
     * the order with a currency-mismatch error) and the cart is restored.
     */
    #[\Override]
    public function isAvailable($quote = null): bool
    {
        if (!$this->_getTalerHelper()->hasCredentials($quote?->getStoreId())) {
            return false;
        }
        return parent::isAvailable($quote);
    }

    /**
     * Redirect URL returned to checkout JS after order placement.
     */
    public function getOrderPlaceRedirectUrl(): string
    {
        return Mage::getUrl('taler/payment/redirect', ['_secure' => true]);
    }

    /**
     * Set order to the configured pending status on placement. The return
     * flow / cron will move it to the configured processing status after the
     * backend reports the order as paid.
     *
     * @param \Maho\DataObject $stateObject
     */
    #[\Override]
    public function initialize($paymentAction, $stateObject): self
    {
        $storeId = null;
        try {
            $info = $this->getInfoInstance();
            if ($info !== null) {
                $source = $info->getOrder() ?: $info->getQuote();
                if ($source !== null && $source->getStoreId() !== null) {
                    $storeId = (int) $source->getStoreId();
                }
            }
        } catch (\Throwable) {
            $storeId = null;
        }

        $statusCode = $this->_getTalerHelper()->getPendingStatus($storeId);

        // Resolve the state the configured status is attached to. Falls back
        // to STATE_PENDING_PAYMENT if the status row is missing or unassigned.
        $state = Mage_Sales_Model_Order::STATE_PENDING_PAYMENT;
        /** @var Mage_Sales_Model_Order_Config $orderConfig */
        $orderConfig = Mage::getSingleton('sales/order_config');
        foreach ($orderConfig->getStatusStates($statusCode) as $statusState) {
            $resolvedState = (string) $statusState->getState();
            if ($resolvedState !== '') {
                $state = $resolvedState;
                break;
            }
        }

        $stateObject->setData('state', $state);
        $stateObject->setData('status', $statusCode);
        $stateObject->setData('is_notified', false);
        return $this;
    }

    /**
     * Create the order in the Taler merchant backend and return the URL of
     * the backend-hosted payment page (QR code / wallet handoff) to redirect
     * the customer to.
     *
     * Called from PaymentController::redirectAction().
     */
    public function createTalerOrder(Mage_Sales_Model_Order $order): string
    {
        $helper = $this->_getTalerHelper();
        $storeId = (int) $order->getStoreId();
        $payment = $order->getPayment();

        if (!$payment) {
            Mage::throwException($helper->__('No payment found for order.'));
        }

        $incrementId = (string) $order->getIncrementId();
        $currency = (string) $order->getOrderCurrencyCode();
        // Amount in the order currency: the customer pays exactly what
        // checkout displayed. Capture later registers the base grand total.
        $amount = $helper->formatAmount($currency, (float) $order->getGrandTotal());

        // Random suffix so a retry after a failed redirect (or a re-placed
        // order) never collides with an id already used in the backend.
        $talerOrderId = $incrementId . '-' . bin2hex(random_bytes(4));

        $products = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $products[] = [
                'description' => (string) $item->getName(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price' => $helper->formatAmount($currency, (float) $item->getPriceInclTax()),
            ];
        }

        $orderSpec = [
            'order_id' => $talerOrderId,
            'amount' => $amount,
            'summary' => $helper->__('Order #%s at %s', $incrementId, $order->getStore()->getFrontendName()),
            // Unique per order, as the protocol requires for repeatable
            // purchases; the backend redirects the wallet's browser here
            // once the order is paid.
            'fulfillment_url' => Mage::getUrl('taler/payment/success', [
                '_secure' => true,
                '_query' => ['order' => $incrementId],
            ]),
            'products' => $products,
        ];

        $refundDelayDays = $helper->getRefundDelayDays($storeId);
        $refundDelay = $refundDelayDays > 0
            ? ['d_us' => $refundDelayDays * 86400 * 1000000]
            : null;

        $api = $this->_getApi($storeId);
        $created = $api->createOrder($orderSpec, $refundDelay);
        $talerOrderId = (string) $created['order_id'];

        // First status call: the "unpaid" response carries the pay URI and the
        // backend-hosted status page URL (with the claim token already
        // embedded — never rebuild that URL by hand).
        $status = $api->getOrder($talerOrderId);
        $orderStatusUrl = (string) ($status['order_status_url'] ?? '');
        if ($orderStatusUrl === '') {
            Mage::throwException($helper->__('Taler: failed to create order. Response: %s', (string) json_encode($status)));
        }

        $payment->setAdditionalInformation('taler_order_id', $talerOrderId);
        $payment->setAdditionalInformation('taler_claim_token', (string) ($created['token'] ?? ''));
        $payment->setAdditionalInformation('taler_order_status_url', $orderStatusUrl);
        $payment->setAdditionalInformation('taler_pay_uri', (string) ($status['taler_pay_uri'] ?? ''));
        $payment->setAdditionalInformation('taler_amount', $amount);
        $payment->save();

        return $orderStatusUrl;
    }

    /**
     * Finalize a pending order the backend reports as paid.
     *
     * Shared by the return-from-payment flow and Cron::processPaymentStatus.
     * Validates the paid contract amount against what we created the order
     * with — on mismatch the order is left untouched in pending_payment and
     * false is returned. Unlike card gateways there is no separate verify/
     * settle call: a Taler "paid" status is authoritative, the backend
     * already holds the coins.
     *
     * @param array $statusResponse The "paid" response from GET /private/orders/{id}
     * @return bool true when the payment was captured, false when rejected
     */
    public function capturePaidOrder(Mage_Sales_Model_Order $order, array $statusResponse): bool
    {
        $helper = $this->_getTalerHelper();
        $storeId = (int) $order->getStoreId();

        if (($statusResponse['order_status'] ?? '') !== 'paid') {
            return false;
        }

        $payment = $order->getPayment();
        if (!$payment) {
            return false;
        }

        // Guard against capturing a different amount than we charged: the
        // contract terms echo what the wallet actually paid.
        $expectedAmount = (string) $payment->getAdditionalInformation('taler_amount');
        $paidAmount = (string) ($statusResponse['contract_terms']['amount'] ?? '');
        if ($expectedAmount !== '' && $paidAmount !== '' && !$helper->amountsMatch($expectedAmount, $paidAmount)) {
            $helper->log(
                "Taler: amount mismatch for order #{$order->getIncrementId()} "
                . "(expected {$expectedAmount}, got {$paidAmount}) — not capturing",
                Mage::LOG_ERROR,
                $storeId,
            );
            return false;
        }

        $payment->setAdditionalInformation('taler_wired', empty($statusResponse['wired']) ? '0' : '1');
        $payment->save();

        // The wallet pays in the order currency, but registerCaptureNotification()
        // expects a base-currency amount: it compares against getBaseGrandTotal()
        // in _isCaptureFinal() and stores it as base_amount_paid_online. Taler
        // always settles the full order, so register the full base total.
        $payment->registerCaptureNotification((float) $order->getBaseGrandTotal());
        $order->save();

        // registerCaptureNotification puts the order in STATE_PROCESSING with
        // the default processing status. Apply the merchant-configured status
        // (which may differ) while leaving the state as-is.
        $processingStatus = $helper->getProcessingStatus($storeId);
        if ($processingStatus !== '' && $processingStatus !== (string) $order->getStatus()) {
            $order->setStatus($processingStatus);
            $order->addStatusHistoryComment(
                $helper->__('Order status set to "%s" per GNU Taler configuration.', $processingStatus),
                $processingStatus,
            )->setIsCustomerNotified(false);
            $order->save();
        }

        return true;
    }

    /**
     * Capture payment — called by registerCaptureNotification() from the
     * return flow / cron.
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function capture(\Maho\DataObject $payment, $amount): self
    {
        $talerOrderId = $payment->getAdditionalInformation('taler_order_id');
        if ($talerOrderId) {
            $payment->setTransactionId((string) $talerOrderId);
            $payment->setIsTransactionClosed(true);
        }
        return $this;
    }

    /**
     * Refund via the Taler merchant backend.
     *
     * Taler refunds are cumulative: the API takes the total refunded so far,
     * not the increment. Track the running total in additional_information
     * so consecutive partial creditmemos add up correctly. The customer's
     * wallet picks the refund up automatically the next time it polls the
     * order (or via the taler_refund_uri).
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    #[\Override]
    public function refund(\Maho\DataObject $payment, $amount): self
    {
        $helper = $this->_getTalerHelper();
        $order = $payment->getOrder();
        $storeId = (int) $order->getStoreId();

        $talerOrderId = (string) $payment->getAdditionalInformation('taler_order_id');
        if ($talerOrderId === '') {
            Mage::throwException($helper->__('Cannot refund: missing Taler order data.'));
        }

        // $amount arrives in base currency; Taler needs the order currency.
        // The creditmemo grand total is in order currency — prefer it, and
        // fall back to $amount (base == order currency in the common case).
        $creditmemo = $payment->getCreditmemo();
        $refundAmount = $creditmemo !== null
            ? (float) $creditmemo->getGrandTotal()
            : (float) $amount;

        $previousTotal = (float) $payment->getAdditionalInformation('taler_refunded_total');
        $newTotal = min($previousTotal + $refundAmount, (float) $order->getGrandTotal());
        $currency = (string) $order->getOrderCurrencyCode();

        $result = $this->_getApi($storeId)->refundOrder(
            $talerOrderId,
            $helper->formatAmount($currency, $newTotal),
            $helper->__('Refund for order #%s', $order->getIncrementId()),
        );

        $payment->setAdditionalInformation('taler_refunded_total', number_format($newTotal, 2, '.', ''));
        $payment->setAdditionalInformation('taler_refund_uri', (string) ($result['taler_refund_uri'] ?? ''));
        $payment->setTransactionId($talerOrderId . '-refund-' . bin2hex(random_bytes(4)));
        $payment->setIsTransactionClosed(false);

        $helper->log(
            "Taler: refunded order #{$order->getIncrementId()} "
            . "(cumulative total {$currency}:" . number_format($newTotal, 2, '.', '') . ')',
            Mage::LOG_INFO,
            $storeId,
        );

        return $this;
    }

    /**
     * Fetch order status from the backend for the admin panel.
     */
    #[\Override]
    public function fetchTransactionInfo(\Mage_Payment_Model_Info $payment, $transactionId): array
    {
        $talerOrderId = (string) $payment->getAdditionalInformation('taler_order_id');
        if ($talerOrderId === '') {
            return [];
        }

        $storeId = (int) $payment->getOrder()->getStoreId();
        try {
            $status = $this->_getApi($storeId)->getOrder($talerOrderId);
            $info = ['order_status' => (string) ($status['order_status'] ?? '')];
            foreach (['refunded', 'refund_pending', 'refund_amount', 'wired', 'deposit_total'] as $key) {
                if (isset($status[$key])) {
                    $info[$key] = is_bool($status[$key]) ? ($status[$key] ? 'yes' : 'no') : (string) $status[$key];
                }
            }
            return $info;
        } catch (\Throwable $e) {
            Mage::logException($e);
            return [];
        }
    }

    protected function _getTalerHelper(): Maho_Taler_Helper_Data
    {
        /** @var Maho_Taler_Helper_Data */
        return Mage::helper('maho_taler');
    }

    protected function _getApi(int $storeId): Maho_Taler_Model_Api
    {
        if ($this->_api === null) {
            /** @var Maho_Taler_Model_Api $api */
            $api = Mage::getModel('maho_taler/api', ['store_id' => $storeId]);
            $this->_api = $api;
        }
        return $this->_api;
    }
}
