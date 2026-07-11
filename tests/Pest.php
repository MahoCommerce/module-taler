<?php

/**
 * SPDX-FileCopyrightText: 2026 Maho <https://mahocommerce.com>
 * SPDX-License-Identifier: OSL-3.0
 * @package Maho_Taler
 */

declare(strict_types=1);

/*
 * Unit tests only: the Maho classes load from the composer classmap, but no
 * Mage application is bootstrapped (no config, no DB). Tests must therefore
 * stick to logic that never calls Mage::app()/Mage::getStoreConfig() — the
 * full payment flow is covered by the manual end-to-end procedure in the
 * README against the Taler demo backend.
 */
