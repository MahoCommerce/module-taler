<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/*
 * Unit suite: the Maho classes load from the composer classmap, but no Mage
 * application is bootstrapped (no config, no DB). Unit tests must therefore
 * stick to logic that never calls Mage::app()/Mage::getStoreConfig().
 *
 * Integration suite: opt-in via TALER_INTEGRATION=1. Requires a Maho
 * installation in this repo (php maho install --db_engine sqlite ...) and
 * talks to a real Taler merchant backend over HTTPS:
 *
 *   TALER_BACKEND_URL  backend base URL   (default https://backend.demo.taler.net)
 *   TALER_INSTANCE     instance id        (default "sandbox"; "@root" = default instance)
 *   TALER_API_TOKEN    /private API token (default "sandbox")
 *
 * The wallet-side payment cannot run headlessly, so integration covers the
 * merchant-side API surface; the paid/refund happy path stays with the
 * manual end-to-end procedure in the README.
 */

uses()
    // Boot the Maho application outside the per-test handler snapshot:
    // Mage::app() installs a global error handler, and doing that inside a
    // test (or its beforeEach) gets the test flagged as risky.
    ->beforeAll(function (): void {
        if (getenv('TALER_INTEGRATION') !== '1') {
            return;
        }
        if (!file_exists(__DIR__ . '/../app/etc/local.xml')) {
            throw new RuntimeException(
                'No Maho installation found. Run: php maho install --db_engine sqlite ... (see .github/workflows/tests.yml)',
            );
        }
        Mage::app('admin');
    })
    ->beforeEach(function (): void {
        if (getenv('TALER_INTEGRATION') !== '1') {
            $this->markTestSkipped('Integration tests are opt-in: set TALER_INTEGRATION=1 (see tests/Pest.php).');
        }
        taler_integration_configure();
    })
    ->in('Integration');

/**
 * Point the module's store config at the backend under test. Runtime
 * setConfig keeps the DB untouched, so tests never leak configuration into
 * the installation, and each test starts from a pristine backend config.
 */
function taler_integration_configure(): void
{
    $instance = getenv('TALER_INSTANCE');
    if ($instance === false || $instance === '') {
        $instance = 'sandbox';
    } elseif ($instance === '@root') {
        $instance = '';
    }

    $store = Mage::app()->getStore();
    $store->setConfig('maho_taler/connection/backend_url', getenv('TALER_BACKEND_URL') ?: 'https://backend.demo.taler.net');
    $store->setConfig('maho_taler/connection/instance', $instance);
    $store->setConfig('maho_taler/connection/api_token', getenv('TALER_API_TOKEN') ?: 'sandbox');
    $store->setConfig('maho_taler/connection/debug', '0');
}
