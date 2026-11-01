<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Block_Form extends Mage_Payment_Block_Form
{
    #[\Override]
    protected function _construct(): void
    {
        parent::_construct();
        $this->setTemplate('maho/taler/form.phtml');
        $this->setMethodTitle(Mage::helper('maho_taler')->__('GNU Taler'));
    }

    /**
     * True when the customer pays on the store page, false when the
     * customer pays on the page of the Taler backend.
     */
    public function isStorePaymentPage(): bool
    {
        /** @var Maho_Taler_Helper_Data $helper */
        $helper = Mage::helper('maho_taler');
        $store = Mage::app()->getStore();
        $storeId = $store !== null ? (int) $store->getId() : null;
        return $helper->getPaymentPage($storeId) === Maho_Taler_Helper_Data::PAYMENT_PAGE_STORE;
    }
}
