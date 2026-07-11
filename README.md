# GNU Taler payment gateway integration for Maho Commerce

![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-module-orange)
![License](https://img.shields.io/badge/license-OSL--3.0-blue)
![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

Accept payments through [GNU Taler](https://www.taler.net), the free and open source, privacy-preserving payment system. Shoppers pay from a Taler wallet (browser extension or mobile app) using digital cash — the payer stays anonymous while every transaction remains taxable and auditable on the merchant side.

> **Status: Early development.** The core payment integration against the [GNU Taler merchant backend](https://docs.taler.net/) REST API is implemented (see the [Roadmap](#roadmap)) but has not yet seen wide production use — test against the demo backend before going live.

## How GNU Taler works

Unlike card gateways, Taler does not process cards or hold merchant funds. You run (or connect to) a **Taler merchant backend** that talks to an exchange. Maho creates an order in the backend, the customer's wallet claims and pays it, and Maho confirms the payment by polling the backend. Settlement to your bank account is handled by the exchange out of band.

## Requirements

- PHP >= 8.3
- Maho Commerce >= 26.5
- Access to a [GNU Taler merchant backend](https://docs.taler.net/taler-merchant-manual.html) instance and the API access token for one of its instances

## Installation

```bash
composer require mahocommerce/module-taler
```

Clear the cache after installation:

```bash
./maho cache:flush
```

## Configuration

Navigate to **System > Configuration > Payment Methods > Taler** in the Maho admin panel.

| Setting | Description | Default |
|---|---|---|
| **Enabled** | Activate the payment method | No |
| **Title** | Payment method name shown at checkout | GNU Taler |
| **Merchant Backend URL** | Base URL of your Taler merchant backend (e.g. `https://backend.demo.taler.net/`) | — |
| **Instance** | Merchant backend instance ID to bill against | — |
| **API Access Token** | Bearer token used to authenticate against the backend (`Authorization: Bearer secret-token:...`) | — |
| **Refund Window (days)** | Refund window granted at order creation; after it passes the backend rejects refunds | 14 |
| **Debug Logging** | Log API requests and responses to `var/log/taler.log` | No |
| **Applicable Countries** | All countries or specific countries only | All |
| **Sort Order** | Display position among payment methods | 100 |
| **Pending / Paid order statuses** | Order statuses applied while awaiting payment and after confirmation | pending_payment / processing |

All settings are store-scoped, so different store views can bill against different backends or instances.

### Sandbox / demo

GNU Taler runs a public demo environment you can use for testing without real money:

- Merchant backend: <https://backend.demo.taler.net/>
- Demo bank (to fund a wallet with `KUDOS`): <https://bank.demo.taler.net/>
- Install the [Taler wallet](https://wallet.taler.net/) browser extension or mobile app to pay

Amounts are expressed in Taler's `CURRENCY:VALUE.FRACTION` format (e.g. `EUR:10.00`, `KUDOS:5.00`).

## How It Works

1. **Order placement** — Customer selects Taler at checkout; the module calls `POST /private/orders` on the merchant backend, which returns an `order_id` (plus a claim token).
2. **Payment** — The customer is redirected to the backend-hosted payment page (`order_status_url`), which shows the QR code / `taler://` pay link and triggers browser-extension wallets, then redirects back to the store once paid.
3. **Confirmation** — The module verifies `GET /private/orders/{order_id}` server-side on return; a 5-minute cron safety net captures orders whose customers never returned and cancels expired ones. Confirmed payments are captured and invoiced.
4. **Refunds** — Full and partial refunds are issued from the Maho admin (online creditmemos) via `POST /private/orders/{order_id}/refund`.

## Roadmap

- [x] Merchant backend order creation + pay URI / QR handoff
- [x] Payment status polling and invoice capture
- [x] Cron safety net for orders left pending
- [x] Online refunds (full + partial) from admin
- [x] Configurable pending / processing order statuses
- [x] Multi-store backend/instance scoping
- [x] Debug logging toggle (`var/log/taler.log`)
- [x] Translations (en, it, da, nl, fi, fr, de, el, pl, pt, pt_BR, ro, es, sv)

## Development

This module ships with the standard Maho CI gates:

- **Pest** (unit tests) — `vendor/bin/pest --testsuite Unit`
- **PHPStan** (level 8) — `vendor/bin/phpstan analyze`
- **Rector** (dry-run) — `vendor/bin/rector -c .rector.php --dry-run`
- **PHP CS Fixer** (dry-run) — `vendor/bin/php-cs-fixer fix --dry-run`
- **PHP / XML syntax checks** — automatic on CI

Run `composer install` and you can execute any of the above locally before pushing.

### Integration tests

The integration suite boots a real Maho application and exercises the API client against a live Taler merchant backend (order create/read/delete, refund and auth error mapping). On CI it runs against the public demo backend by default; set the `TALER_BACKEND_URL`, `TALER_INSTANCE` and `TALER_API_TOKEN` repository secrets to test against your own backend instead (use `@root` as the instance for the default instance at the backend root).

To run it locally, install Maho into this repo once (SQLite, no services needed) and opt in via the environment:

```bash
composer install
php maho install --license_agreement_accepted yes \
  --locale en_US --timezone Europe/Rome --default_currency EUR \
  --db_engine sqlite --db_name taler_test.sqlite \
  --db_host localhost --db_user '' --db_pass '' \
  --url 'http://taler.test/' \
  --admin_lastname Test --admin_firstname Test \
  --admin_email test@example.com \
  --admin_username admin --admin_password 'AdminTest123456!'

TALER_INTEGRATION=1 vendor/bin/pest --testsuite Integration
```

The wallet side cannot run headlessly, so the paid/refund happy path remains a manual test (see [Sandbox / demo](#sandbox--demo)).

## License

This module is licensed under the [Open Software License v3.0](LICENSE.txt).

## Links

- [Maho Commerce](https://mahocommerce.com)
- [GNU Taler](https://www.taler.net)
- [GNU Taler documentation](https://docs.taler.net/)
- [GNU Taler merchant backend API](https://docs.taler.net/core/api-merchant.html)
