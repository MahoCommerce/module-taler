<?php

/**
 * Checks the backend connection and registers the webhook when the admin saves the payment configuration.
 *
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Model_Observer
{
    #[Maho\Config\Observer('admin_system_config_changed_section_payment', area: 'adminhtml')]
    public function syncBackendConnection(\Maho\Event\Observer $observer): void
    {
        // The connection fields do not show at store view scope.
        if ((string) $observer->getEvent()->getData('store') !== '') {
            return;
        }

        $scope = $this->_resolveScope((string) $observer->getEvent()->getData('website'));
        if ($scope === null) {
            return;
        }

        /** @var Maho_Taler_Helper_Data $helper */
        $helper = Mage::helper('maho_taler');
        if (!$helper->hasCredentials($scope['config_store_id'])) {
            return;
        }

        // Read the settings before _syncCurrency() can reinit the config.
        $webhookEnabled = $helper->isWebhookEnabled($scope['config_store_id']);
        $secret = $helper->getWebhookSecret(0);

        /** @var Mage_Adminhtml_Model_Session $session */
        $session = Mage::getSingleton('adminhtml/session');
        /** @var Maho_Taler_Model_Api $api */
        $api = Mage::getModel('maho_taler/api', ['store_id' => $scope['config_store_id']]);

        try {
            $this->_syncCurrency($api, $scope);
        } catch (\Throwable $e) {
            $session->addError($helper->__('GNU Taler: the connection check failed: %s', $e->getMessage()));
            return;
        }

        try {
            $this->_syncWebhook($api, $scope, $webhookEnabled, $secret);
        } catch (\Throwable $e) {
            $session->addError($helper->__('GNU Taler: the webhook registration failed: %s', $e->getMessage()));
        }
    }

    /**
     * Config values are read from $config_store_id: store 0 (admin) gives the
     * default scope values, the default store of a website gives the website
     * values. $url_store_id is the store view that builds the webhook URL.
     *
     * @return array{scope: string, scope_id: int, config_store_id: int, url_store_id: int}|null
     */
    protected function _resolveScope(string $websiteCode): ?array
    {
        if ($websiteCode === '') {
            $store = Mage::app()->getDefaultStoreView();
            if ($store === null) {
                return null;
            }
            return ['scope' => 'default', 'scope_id' => 0, 'config_store_id' => 0, 'url_store_id' => (int) $store->getId()];
        }

        $website = Mage::app()->getWebsite($websiteCode);
        $store = $website->getDefaultStore();
        if ($store === null) {
            return null;
        }
        return [
            'scope' => 'websites',
            'scope_id' => (int) $website->getId(),
            'config_store_id' => (int) $store->getId(),
            'url_store_id' => (int) $store->getId(),
        ];
    }

    /**
     * @param array{scope: string, scope_id: int, config_store_id: int, url_store_id: int} $scope
     */
    protected function _syncCurrency(Maho_Taler_Model_Api $api, array $scope): void
    {
        /** @var Maho_Taler_Helper_Data $helper */
        $helper = Mage::helper('maho_taler');
        $session = Mage::getSingleton('adminhtml/session');

        $config = $api->getConfig();
        $backendCurrency = strtoupper((string) ($config['currency'] ?? ''));
        $supported = array_map(strtoupper(...), array_keys((array) ($config['currencies'] ?? [])));
        if ($backendCurrency !== '') {
            $supported[] = $backendCurrency;
        }

        $configured = $helper->getCurrency($scope['config_store_id']);
        if ($configured === '') {
            if ($backendCurrency === '') {
                return;
            }
            Mage::getModel('core/config')->saveConfig(
                'maho_taler/connection/currency',
                $backendCurrency,
                $scope['scope'],
                $scope['scope_id'],
            );
            Mage::getConfig()?->reinit();
            $session->addSuccess($helper->__('GNU Taler: the accepted currency is now %s, as the backend reports.', $backendCurrency));
            return;
        }

        if (!in_array($configured, $supported, true)) {
            $session->addWarning($helper->__(
                'GNU Taler: the accepted currency is %s, but the backend supports only %s.',
                $configured,
                implode(', ', array_unique($supported)),
            ));
        }
    }

    /**
     * @param array{scope: string, scope_id: int, config_store_id: int, url_store_id: int} $scope
     */
    protected function _syncWebhook(Maho_Taler_Model_Api $api, array $scope, bool $enabled, #[\SensitiveParameter] string $secret): void
    {
        /** @var Maho_Taler_Helper_Data $helper */
        $helper = Mage::helper('maho_taler');
        $session = Mage::getSingleton('adminhtml/session');

        $url = Mage::getUrl('taler/webhook', [
            '_store' => $scope['url_store_id'],
            '_secure' => true,
        ]);
        $webhookId = $helper->getWebhookId($url);

        if (!$enabled) {
            $api->deleteWebhook($webhookId);
            return;
        }

        $api->upsertWebhook($webhookId, $this->getWebhookDetails($url, $secret !== '' ? $secret : $this->_createSecret()));
        $session->addSuccess($helper->__('GNU Taler: the webhook %s sends payment notifications to %s.', $webhookId, $url));
    }

    /**
     * Body of the webhook create and update calls. The backend expands
     * {{webhook_type}} and {{order_id}} when it sends the call.
     *
     * @return array{event_type: string, url: string, http_method: string, header_template: string, body_template: string}
     */
    public function getWebhookDetails(string $url, #[\SensitiveParameter] string $secret): array
    {
        return [
            'event_type' => 'pay',
            'url' => $url,
            'http_method' => 'POST',
            // One "Name: value" header per line.
            'header_template' => Maho_Taler_Helper_Data::WEBHOOK_SECRET_HEADER . ': ' . $secret . "\nContent-Type: application/json",
            'body_template' => '{"webhook_type":"{{webhook_type}}","order_id":"{{order_id}}"}',
        ];
    }

    /**
     * All stores share one secret at the default scope.
     */
    protected function _createSecret(): string
    {
        $secret = bin2hex(random_bytes(32));
        Mage::getModel('core/config')->saveConfig(
            'maho_taler/connection/webhook_secret',
            Mage::helper('core')->encrypt($secret),
        );
        Mage::getConfig()?->reinit();
        return $secret;
    }
}
