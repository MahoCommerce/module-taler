<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

class Maho_Taler_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const LOG_FILE = 'taler.log';

    protected $_moduleName = 'Maho_Taler';

    public function getBackendUrl(?int $storeId = null): string
    {
        return rtrim(trim((string) Mage::getStoreConfig('maho_taler/connection/backend_url', $storeId)), '/');
    }

    public function getInstance(?int $storeId = null): string
    {
        return trim((string) Mage::getStoreConfig('maho_taler/connection/instance', $storeId));
    }

    /**
     * Base URL all API endpoints are relative to: the backend root for the
     * default instance, or the /instances/{id} subtree for a named instance.
     * Each instance serves its own /config and /private/* endpoints.
     */
    public function getApiBaseUrl(?int $storeId = null): string
    {
        return $this->buildApiBaseUrl($this->getBackendUrl($storeId), $this->getInstance($storeId));
    }

    public function buildApiBaseUrl(string $backendUrl, string $instance): string
    {
        $url = rtrim(trim($backendUrl), '/');
        $instance = trim($instance);
        if ($instance !== '') {
            $url .= '/instances/' . rawurlencode($instance);
        }
        return $url;
    }

    /**
     * Bearer token for the /private API.
     */
    public function getApiToken(?int $storeId = null): string
    {
        return $this->normalizeToken((string) Mage::getStoreConfig('maho_taler/connection/api_token', $storeId));
    }

    /**
     * Admins paste tokens both with and without the RFC 8959 "secret-token:"
     * prefix, so normalize to the prefixed form the backend expects.
     */
    public function normalizeToken(#[\SensitiveParameter] string $token): string
    {
        $token = trim($token);
        if ($token === '') {
            return '';
        }
        if (!str_starts_with($token, 'secret-token:')) {
            $token = 'secret-token:' . $token;
        }
        return $token;
    }

    public function hasCredentials(?int $storeId = null): bool
    {
        return $this->getBackendUrl($storeId) !== ''
            && $this->getApiToken($storeId) !== '';
    }

    /**
     * Refund window granted at order creation. After it passes the backend
     * rejects refunds (410 Gone), so it should match the shop's return policy.
     */
    public function getRefundDelayDays(?int $storeId = null): int
    {
        return max(0, (int) Mage::getStoreConfig('maho_taler/connection/refund_delay_days', $storeId));
    }

    public function isDebug(?int $storeId = null): bool
    {
        return Mage::getStoreConfigFlag('maho_taler/connection/debug', $storeId);
    }

    /**
     * Status code applied while the customer is paying from their wallet.
     * Falls back to 'pending_payment' if the config is missing.
     */
    public function getPendingStatus(?int $storeId = null): string
    {
        $status = (string) Mage::getStoreConfig('payment/taler/order_status_pending', $storeId);
        return $status !== '' ? $status : 'pending_payment';
    }

    /**
     * Status code applied after the backend confirms the payment.
     * Falls back to 'processing' if the config is missing.
     */
    public function getProcessingStatus(?int $storeId = null): string
    {
        $status = (string) Mage::getStoreConfig('payment/taler/order_status_processing', $storeId);
        return $status !== '' ? $status : 'processing';
    }

    /**
     * Format a decimal amount as a Taler amount string ("CURRENCY:VALUE.FF").
     * Two decimals is exact for Maho totals and well within Taler's maximum
     * of eight fractional digits.
     */
    public function formatAmount(string $currency, float $value): string
    {
        return strtoupper($currency) . ':' . number_format($value, 2, '.', '');
    }

    /**
     * Parse a Taler amount string ("CURRENCY:VALUE.FF") into its parts.
     *
     * @return array{currency: string, value: float}
     */
    public function parseAmount(string $amount): array
    {
        $parts = explode(':', $amount, 2);
        return [
            'currency' => strtoupper(trim($parts[0])),
            'value' => (float) ($parts[1] ?? 0),
        ];
    }

    /**
     * Compare two Taler amount strings for equality, tolerating formatting
     * differences ("KUDOS:10" vs "KUDOS:10.00").
     */
    public function amountsMatch(string $a, string $b): bool
    {
        $parsedA = $this->parseAmount($a);
        $parsedB = $this->parseAmount($b);
        return $parsedA['currency'] === $parsedB['currency']
            && abs($parsedA['value'] - $parsedB['value']) < 0.005;
    }

    /**
     * Write to var/log/taler.log. Payment lifecycle events (capture, cancel,
     * errors) log unconditionally; LOG_DEBUG lines (API request/response
     * dumps) only when the debug toggle is on for the store.
     */
    public function log(string $message, \Monolog\Level $level = Mage::LOG_INFO, ?int $storeId = null): void
    {
        if ($level === Mage::LOG_DEBUG && !$this->isDebug($storeId)) {
            return;
        }
        Mage::log($message, $level, self::LOG_FILE);
    }
}
