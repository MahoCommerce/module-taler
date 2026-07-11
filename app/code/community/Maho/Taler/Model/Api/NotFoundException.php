<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/**
 * The merchant backend answered 404 for an order: it was deleted, expired,
 * or belongs to a different instance. Callers use this to distinguish
 * "order gone from the backend" (cancel it locally) from transport or
 * configuration errors (leave the order alone and retry later).
 */
class Maho_Taler_Model_Api_NotFoundException extends Mage_Core_Exception {}
