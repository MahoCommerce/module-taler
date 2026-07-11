<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Block_Info extends Mage_Payment_Block_Info
{
    #[\Override]
    protected function _prepareSpecificInformation($transport = null): \Maho\DataObject
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $payment = $this->getInfo();
        $helper = Mage::helper('maho_taler');

        $data = [];

        $talerOrderId = $payment->getAdditionalInformation('taler_order_id');
        if ($talerOrderId) {
            $data[$helper->__('Taler Order ID')] = $talerOrderId;
        }

        $amount = $payment->getAdditionalInformation('taler_amount');
        if ($amount) {
            $data[$helper->__('Amount')] = $amount;
        }

        $refundedTotal = $payment->getAdditionalInformation('taler_refunded_total');
        if ($refundedTotal) {
            $data[$helper->__('Refunded Total')] = $refundedTotal;
        }

        $orderStatusUrl = $payment->getAdditionalInformation('taler_order_status_url');
        if ($orderStatusUrl) {
            $data[$helper->__('Order Status URL')] = $orderStatusUrl;
        }

        $payUri = $payment->getAdditionalInformation('taler_pay_uri');
        if ($payUri) {
            $data[$helper->__('Pay URI')] = $payUri;
        }

        return $transport->addData($data);
    }
}
