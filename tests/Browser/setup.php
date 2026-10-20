<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * Prepares the Maho installation in this repo for the Playwright checkout
 * test: storefront URL, Taler connection, a store currency the backend
 * accepts, cheap flat-rate shipping and one product to buy.
 *
 * Unlike the Pest integration suite this writes to core_config_data, because
 * the storefront runs in a separate PHP server process.
 *
 * Env: BROWSER_BASE_URL (default http://127.0.0.1:8000/), TALER_BACKEND_URL,
 * TALER_INSTANCE ("@root" = default instance), TALER_API_TOKEN.
 */

require __DIR__ . '/../../vendor/autoload.php';

Mage::app('admin');

$baseUrl = getenv('BROWSER_BASE_URL') ?: 'http://127.0.0.1:8000/';
$instance = getenv('TALER_INSTANCE');
if ($instance === false || $instance === '') {
    $instance = 'sandbox';
} elseif ($instance === '@root') {
    $instance = '';
}

$config = Mage::getModel('core/config');
$save = static fn(string $path, string $value) => $config->saveConfig($path, $value);

$save('web/unsecure/base_url', $baseUrl);
$save('web/secure/base_url', $baseUrl);
$save('web/secure/use_in_frontend', '0');
$save('web/url/redirect_to_base', '0');

$save('payment/taler/active', '1');
$save('maho_taler/connection/backend_url', getenv('TALER_BACKEND_URL') ?: 'https://backend.demo.taler.net');
$save('maho_taler/connection/instance', $instance);
$save('maho_taler/connection/api_token', Mage::helper('core')->encrypt(getenv('TALER_API_TOKEN') ?: 'sandbox'));
$save('maho_taler/connection/debug', '1');

// The order is created in the store currency, so the store must sell in the
// backend's currency (KUDOS on the demo backend).
Mage::getConfig()?->reinit();
Mage::app()->reinitStores();
$currency = (string) Mage::getModel('maho_taler/api', ['store_id' => 0])->getConfig()['currency'];
$save('currency/options/base', $currency);
$save('currency/options/default', $currency);
$save('currency/options/allow', $currency);

$save('carriers/flatrate/active', '1');
$save('carriers/flatrate/type', 'O');
$save('carriers/flatrate/price', '0.50');

$sku = 'taler-browser-test';
$product = Mage::getModel('catalog/product');
$productId = $product->getIdBySku($sku);
if ($productId) {
    $product->load($productId);
} else {
    $product
        ->setSku($sku)
        ->setTypeId(Mage_Catalog_Model_Product_Type::TYPE_SIMPLE)
        ->setAttributeSetId($product->getDefaultAttributeSetId())
        ->setWebsiteIds([(int) Mage::app()->getWebsite(true)->getId()])
        ->setName('Taler Browser Test Product')
        ->setUrlKey('taler-browser-test-product')
        ->setDescription('Product used by the Playwright checkout test.')
        ->setShortDescription('Product used by the Playwright checkout test.')
        ->setWeight(1)
        ->setTaxClassId(0);
}
$product
    ->setStatus(Mage_Catalog_Model_Product_Status::STATUS_ENABLED)
    ->setVisibility(Mage_Catalog_Model_Product_Visibility::VISIBILITY_BOTH)
    ->setPrice(1.50)
    ->setStockData(['use_config_manage_stock' => 0, 'manage_stock' => 0, 'is_in_stock' => 1, 'qty' => 1000])
    ->save();

Mage::getModel('index/indexer')->getProcessesCollection()->walk('reindexEverything');
Mage::app()->getCache()->flush();

echo "Store ready at {$baseUrl} (product {$sku})\n";
