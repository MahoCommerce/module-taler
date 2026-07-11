<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Minimal client for the GNU Taler merchant backend REST API
 * (https://docs.taler.net/core/api-merchant.html). Only the four endpoints
 * the payment flow needs — deliberately no SDK dependency.
 */
class Maho_Taler_Model_Api
{
    protected ?int $_storeId = null;

    public function __construct(array $args = [])
    {
        if (isset($args['store_id'])) {
            $this->_storeId = (int) $args['store_id'];
        }
    }

    public function setStoreId(int $storeId): self
    {
        $this->_storeId = $storeId;
        return $this;
    }

    protected function _getHelper(): Maho_Taler_Helper_Data
    {
        return Mage::helper('maho_taler');
    }

    /**
     * Fetch the backend/instance configuration. Public endpoint; used to
     * validate the connection settings and read the backend currency.
     *
     * @throws Mage_Core_Exception
     */
    public function getConfig(): array
    {
        $response = $this->_request('GET', '/config');

        if (($response['name'] ?? '') !== 'taler-merchant') {
            Mage::throwException(
                $this->_getHelper()->__('The configured URL does not point to a Taler merchant backend.'),
            );
        }

        return $response;
    }

    /**
     * Create an order in the merchant backend.
     *
     * @param array $order Contract terms (amount, summary, fulfillment_url, ...)
     * @param array|null $refundDelay RelativeTime ({"d_us": ...}) added to the
     *                                pay deadline to form the refund deadline
     * @return array<string, mixed> Contains order_id and, when requested, the claim token
     * @throws Mage_Core_Exception
     */
    public function createOrder(array $order, ?array $refundDelay = null): array
    {
        $body = [
            'order' => $order,
            'create_token' => true,
        ];
        if ($refundDelay !== null) {
            $body['refund_delay'] = $refundDelay;
        }

        $response = $this->_request('POST', '/private/orders', $body);

        if (!isset($response['order_id'])) {
            Mage::throwException(
                $this->_getHelper()->__('Taler: failed to create order. Response: %s', (string) json_encode($response)),
            );
        }

        return $response;
    }

    /**
     * Get the status of an order. The response shape is discriminated by
     * order_status: "unpaid" | "claimed" | "paid".
     *
     * @param int|null $timeoutMs Long-poll: the backend holds the request
     *                            until the status changes or the timeout expires
     * @throws Maho_Taler_Model_Api_NotFoundException when the backend no longer knows the order
     * @throws Mage_Core_Exception
     */
    public function getOrder(string $orderId, ?int $timeoutMs = null): array
    {
        $query = [];
        if ($timeoutMs !== null && $timeoutMs > 0) {
            $query['timeout_ms'] = (string) $timeoutMs;
        }

        return $this->_request('GET', '/private/orders/' . rawurlencode($orderId), [], $query);
    }

    /**
     * Grant a refund on a paid order.
     *
     * Taler refunds are cumulative: $refundTotal is the TOTAL amount to be
     * refunded so far ("KUDOS:5.00"), not the increment — callers must add
     * the new refund to what was already refunded.
     *
     * @return array<string, mixed> Contains taler_refund_uri and h_contract
     * @throws Mage_Core_Exception
     */
    public function refundOrder(string $orderId, string $refundTotal, string $reason): array
    {
        $helper = $this->_getHelper();

        return $this->_request(
            'POST',
            '/private/orders/' . rawurlencode($orderId) . '/refund',
            ['refund' => $refundTotal, 'reason' => $reason],
            [],
            [
                403 => $helper->__('The refund amount exceeds the amount paid via Taler.'),
                404 => $helper->__('The order is unknown to the Taler merchant backend.'),
                410 => $helper->__('The Taler refund deadline for this order has passed.'),
            ],
        );
    }

    /**
     * Perform an HTTP request against the merchant backend.
     *
     * @param array<int, string> $errorMessages Per-HTTP-status user-facing
     *                                          message overriding the generic one
     * @throws Maho_Taler_Model_Api_NotFoundException on HTTP 404
     * @throws Mage_Core_Exception
     */
    protected function _request(
        string $method,
        string $endpoint,
        array $body = [],
        array $query = [],
        array $errorMessages = [],
    ): array {
        $helper = $this->_getHelper();
        $url = $helper->getApiBaseUrl($this->_storeId) . $endpoint;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $headers = ['Accept' => 'application/json'];
        $token = $helper->getApiToken($this->_storeId);
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        // Long-polling requests must not be cut off by the client timeout.
        $timeout = 30;
        if (isset($query['timeout_ms'])) {
            $timeout = max($timeout, (int) ceil((int) $query['timeout_ms'] / 1000) + 10);
        }

        $options = [
            'timeout' => $timeout,
            'headers' => $headers,
        ];

        if ($body && $method !== 'GET') {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            $client = \Symfony\Component\HttpClient\HttpClient::create();
            $response = $client->request($method, $url, $options);
            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
            $result = $content !== '' ? Mage::helper('core')->jsonDecode($content) : [];
            if (!is_array($result)) {
                $result = [];
            }

            $helper->log(
                "Taler API: {$method} {$endpoint} -> {$statusCode}: {$content}",
                Mage::LOG_DEBUG,
                $this->_storeId,
            );

            if ($statusCode >= 400) {
                $helper->log(
                    "Taler API error: {$method} {$endpoint} -> {$statusCode}: {$content}",
                    Mage::LOG_ERROR,
                    $this->_storeId,
                );

                // Taler error bodies carry a numeric "code" plus human-readable
                // "hint" (and sometimes "detail") — prefer those for the message.
                $errorMsg = $result['hint'] ?? $result['detail'] ?? "HTTP {$statusCode}";
                $message = $errorMessages[$statusCode]
                    ?? $helper->__('Taler API error: %s', $errorMsg);

                if ($statusCode === 404) {
                    throw new Maho_Taler_Model_Api_NotFoundException($message);
                }
                Mage::throwException($message);
            }

            return $result;
        } catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface $e) {
            $helper->log(
                "Taler API transport error: {$method} {$endpoint} -> {$e->getMessage()}",
                Mage::LOG_ERROR,
                $this->_storeId,
            );
            Mage::throwException($helper->__('Taler connection error: %s', $e->getMessage()));
        }
    }
}
