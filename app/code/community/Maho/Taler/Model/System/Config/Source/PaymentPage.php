<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Model_System_Config_Source_PaymentPage
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('maho_taler');
        return [
            ['value' => Maho_Taler_Helper_Data::PAYMENT_PAGE_STORE, 'label' => $helper->__('Store page')],
            ['value' => Maho_Taler_Helper_Data::PAYMENT_PAGE_BACKEND, 'label' => $helper->__('Taler backend page')],
        ];
    }
}
