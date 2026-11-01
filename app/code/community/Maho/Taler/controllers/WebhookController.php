<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_WebhookController extends Mage_Core_Controller_Front_Action
{
    /**
     * The shared secret header protects this endpoint, not a session or a form key.
     */
    #[\Override]
    public function preDispatch(): self
    {
        $this->setFlag('', self::FLAG_NO_PRE_DISPATCH, true);
        $this->setFlag('', self::FLAG_NO_START_SESSION, true);
        return parent::preDispatch();
    }

    #[Maho\Config\Route('/taler/webhook', methods: ['POST'])]
    public function indexAction(): void
    {
        /** @var Maho_Taler_Model_Webhook $webhook */
        $webhook = Mage::getModel('maho_taler/webhook');
        $status = $webhook->handle(
            (string) $this->getRequest()->getHeader(Maho_Taler_Helper_Data::WEBHOOK_SECRET_HEADER),
            (string) $this->getRequest()->getRawBody(),
        );
        $this->getResponse()->setHttpResponseCode($status);
    }
}
