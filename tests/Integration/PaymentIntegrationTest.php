<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Real-payment tests: a headless GNU Taler wallet (taler-wallet-cli)
 * withdraws play money from the demo bank, pays an order created by
 * Maho_Taler_Model_Api, and the tests assert the exact backend responses the
 * success controller, cron and refund flow consume.
 *
 * Requires taler-wallet-cli (Taler's *-testing apt channel, matching the demo
 * deployment) and a KUDOS demo-style environment; skipped otherwise.
 * Overrides: TALER_WALLET_CLI (binary), TALER_WALLET_BANK_URL,
 * TALER_WALLET_EXCHANGE_URL (withdraw endpoints).
 */
function talerWalletBin(): ?string
{
    static $resolved = false;
    static $bin = null;
    if (!$resolved) {
        $resolved = true;
        $candidate = getenv('TALER_WALLET_CLI') ?: 'taler-wallet-cli';
        exec('command -v ' . escapeshellarg($candidate) . ' 2>/dev/null', $out, $rc);
        $bin = $rc === 0 ? $candidate : null;
    }
    return $bin;
}

function talerWallet(string ...$args): string
{
    $cmd = escapeshellarg((string) talerWalletBin());
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    exec($cmd . ' 2>&1', $out, $rc);
    $output = implode("\n", $out);
    if ($rc !== 0) {
        throw new RuntimeException("taler-wallet-cli failed ({$rc}): {$output}");
    }
    return $output;
}

/**
 * Withdraw once per process; every paid-path test shares the balance.
 */
function talerWalletEnsureFunds(string $amount): void
{
    static $funded = false;
    if ($funded) {
        return;
    }
    $args = ['testing', 'withdraw-kudos', '--amount=' . $amount];
    $bankUrl = getenv('TALER_WALLET_BANK_URL');
    if ($bankUrl !== false && $bankUrl !== '') {
        $args[] = '--bank-url=' . $bankUrl;
    }
    $exchangeUrl = getenv('TALER_WALLET_EXCHANGE_URL');
    if ($exchangeUrl !== false && $exchangeUrl !== '') {
        $args[] = '--exchange-url=' . $exchangeUrl;
    }
    talerWallet(...$args);
    talerWallet('run-until-done');
    $funded = true;
}

beforeEach(function (): void {
    if (talerWalletBin() === null) {
        $this->markTestSkipped('taler-wallet-cli not found; the paid-path tests need it (see README).');
    }
    if (talerCurrency() !== 'KUDOS') {
        $this->markTestSkipped('Paid-path tests only run against a KUDOS demo-style environment.');
    }
});

test('a real wallet pays an order, and refunds accumulate until the wallet collects them', function (): void {
    /** @var Maho_Taler_Helper_Data $helper */
    $helper = Mage::helper('maho_taler');

    talerWalletEnsureFunds('KUDOS:10');

    // Create the order exactly like createTalerOrder() does.
    $orderId = talerTestOrderId();
    talerApi()->createOrder(talerOrderSpec($orderId, 2.00), ['d_us' => 86400000000]);
    $unpaid = talerApi()->getOrder($orderId);
    expect($unpaid['order_status'])->toBe('unpaid');

    // The wallet pays the same taler_pay_uri a customer would scan.
    talerWallet('handle-uri', '-y', (string) $unpaid['taler_pay_uri']);
    talerWallet('run-until-done');

    // This is the exact response processPaymentStatus()/capturePaidOrder()
    // act on: paid, amount echoed in the contract terms, nothing refunded.
    $paid = [];
    foreach (range(1, 10) as $i) {
        $paid = talerApi()->getOrder($orderId, 5000);
        if (($paid['order_status'] ?? '') === 'paid') {
            break;
        }
    }
    expect($paid['order_status'])->toBe('paid')
        ->and($paid['refunded'])->toBeFalse()
        ->and($helper->amountsMatch((string) $paid['contract_terms']['amount'], 'KUDOS:2.00'))->toBeTrue();

    // First partial refund: the module would send the creditmemo total.
    $refund = talerApi()->refundOrder($orderId, $helper->formatAmount('KUDOS', 0.50), 'integration refund 1');
    expect($refund['taler_refund_uri'] ?? '')->toStartWith('taler');

    $afterFirst = talerApi()->getOrder($orderId);
    expect($afterFirst['refunded'])->toBeTrue()
        ->and($helper->amountsMatch((string) $afterFirst['refund_amount'], 'KUDOS:0.50'))->toBeTrue();

    // Second creditmemo: the API takes the new cumulative TOTAL (1.20), not
    // the increment. This is the semantics refund() implements in the module.
    talerApi()->refundOrder($orderId, $helper->formatAmount('KUDOS', 1.20), 'integration refund 2');
    $afterSecond = talerApi()->getOrder($orderId);
    expect($helper->amountsMatch((string) $afterSecond['refund_amount'], 'KUDOS:1.20'))->toBeTrue();

    // Refunding beyond the paid amount hits the 403 mapping.
    expect(fn() => talerApi()->refundOrder($orderId, $helper->formatAmount('KUDOS', 100.00), 'too much'))
        ->toThrow(Mage_Core_Exception::class);

    // The customer's wallet collects the refund, which clears refund_pending.
    talerWallet('handle-uri', '-y', (string) $refund['taler_refund_uri']);
    talerWallet('run-until-done');
    $afterPickup = [];
    foreach (range(1, 10) as $i) {
        $afterPickup = talerApi()->getOrder($orderId, 5000);
        if (($afterPickup['refund_pending'] ?? true) === false) {
            break;
        }
    }
    expect($afterPickup['refund_pending'])->toBeFalse();
});
