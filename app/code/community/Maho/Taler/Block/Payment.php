<?php

/**
 * Store payment page: QR code, wallet link and status polling.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Block_Payment extends Mage_Core_Block_Template
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('maho/taler/payment.phtml');
    }

    public function getOrder(): ?Mage_Sales_Model_Order
    {
        $order = Mage::registry('taler_order');
        return $order instanceof Mage_Sales_Model_Order ? $order : null;
    }

    /**
     * Empty unless it is a taler:// (or taler+http:// on test backends) URI, so it is safe as a link.
     */
    public function getPayUri(): string
    {
        $payUri = $this->_getPaymentInfo('taler_pay_uri');
        return preg_match('#^taler(\+http)?://#i', $payUri) === 1 ? $payUri : '';
    }

    /**
     * Payment page of the Taler backend, as a fallback for the customer.
     */
    public function getBackendPageUrl(): string
    {
        return $this->_getPaymentInfo('taler_order_status_url');
    }

    /**
     * Inline SVG made by BaconQrCode from the pay URI only, so it is safe to print as is.
     */
    public function getQrCodeSvg(): string
    {
        $payUri = $this->getPayUri();
        if ($payUri === '') {
            return '';
        }
        $writer = new \BaconQrCode\Writer(
            new \BaconQrCode\Renderer\ImageRenderer(
                new \BaconQrCode\Renderer\RendererStyle\RendererStyle(280),
                new \BaconQrCode\Renderer\Image\SvgImageBackEnd(),
            ),
        );
        return $writer->writeString($payUri);
    }

    public function getFormattedAmount(): string
    {
        $order = $this->getOrder();
        return $order === null ? '' : $order->formatPriceTxt((float) $order->getGrandTotal());
    }

    public function getIncrementId(): string
    {
        return (string) $this->getOrder()?->getIncrementId();
    }

    protected function _getPaymentInfo(string $key): string
    {
        $payment = $this->getOrder()?->getPayment();
        return $payment ? (string) $payment->getAdditionalInformation($key) : '';
    }

    public function getStatusUrl(): string
    {
        return $this->getUrl('taler/payment/status', ['_secure' => true]);
    }

    public function getCancelUrl(): string
    {
        return $this->getUrl('taler/payment/cancel', ['_secure' => true]);
    }
}
