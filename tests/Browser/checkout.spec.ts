import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { expect, test } from '@playwright/test';

/**
 * End-to-end storefront test: a guest buys the product created by setup.php,
 * picks GNU Taler, lands on the merchant backend's payment page, and a
 * headless wallet (taler-wallet-cli) pays the taler://pay URI shown there,
 * like a phone wallet scanning the QR code. The backend page then sends the
 * browser back to the store, and the order must end up invoiced.
 *
 * Env: TALER_WALLET_CLI (wallet binary, default taler-wallet-cli),
 * TALER_WALLET_BANK_URL / TALER_WALLET_EXCHANGE_URL (withdraw endpoints).
 */

const repoRoot = fileURLToPath(new URL('../..', import.meta.url));

function wallet(...args: string[]): string {
    return execFileSync(process.env.TALER_WALLET_CLI ?? 'taler-wallet-cli', args, {
        encoding: 'utf8',
        timeout: 120_000,
    });
}

function fundWallet(amount: string): void {
    const args = ['testing', 'withdraw-kudos', `--amount=${amount}`, '--wait', '--timeout=2m'];
    if (process.env.TALER_WALLET_BANK_URL) {
        args.push(`--bank-url=${process.env.TALER_WALLET_BANK_URL}`);
    }
    if (process.env.TALER_WALLET_EXCHANGE_URL) {
        args.push(`--exchange-url=${process.env.TALER_WALLET_EXCHANGE_URL}`);
    }
    wallet(...args);
}

type OrderInfo = {
    id: number;
    state: string;
    status: string;
    currency: string;
    grand_total: number;
    total_paid: number;
    total_refunded: number;
    invoices: number;
    creditmemos: number;
    payment_method: string | null;
    taler_order_id: string | null;
    taler_amount: string | null;
    backend: { order_status: string; refunded: boolean; refund_amount: string } | null;
};

/** The numeric value of a Taler amount such as "KUDOS:1.5". */
function talerValue(amount: string | undefined): number {
    return Number(amount?.split(':')[1]);
}

function inspect<T>(command: 'product' | 'order', argument: string): T {
    const json = execFileSync('php', ['tests/Browser/inspect.php', command, argument], { cwd: repoRoot, encoding: 'utf8' });
    return JSON.parse(json) as T;
}

// The refund test works on the order the checkout test paid.
test.describe.configure({ mode: 'serial' });

let incrementId: string | undefined;

test('a guest pays with GNU Taler and comes back to an invoiced order', async ({ page }) => {
    fundWallet('KUDOS:5');

    const product = inspect<{ id: number }>('product', 'taler-browser-test');
    await page.goto(`catalog/product/view/id/${product.id}/`);
    await page.locator('#product_addtocart_form .btn-cart').first().click();
    await expect(page.getByText('was added to your shopping cart')).toBeVisible();

    await page.goto('checkout/onepage/');

    // One-page checkout: guest billing address, shipping to the same address.
    // Flat rate is the only shipping method, so the checkout selects it.
    const billing = page.locator('#co-billing-form');
    await billing.locator('[id="billing:firstname"]').fill('Taler');
    await billing.locator('[id="billing:lastname"]').fill('Tester');
    await billing.locator('[id="billing:email"]').fill('taler-browser-test@example.com');
    // A country without a region list keeps the form free of conditional fields.
    await billing.locator('[id="billing:country_id"]').selectOption('NL');
    await billing.locator('[id="billing:street1"]').fill('Damrak 1');
    await billing.locator('[id="billing:city"]').fill('Amsterdam');
    await billing.locator('[id="billing:postcode"]').fill('1012 LG');
    await billing.locator('[id="billing:telephone"]').fill('0201234567');
    await page.getByRole('radio', { name: 'Ship to this address' }).check();

    await page.getByRole('radio', { name: 'GNU Taler' }).check();

    // Every field change saves its checkout section over AJAX. Placing the
    // order while a save is in flight races it, so wait for them to settle.
    await page.waitForLoadState('networkidle');

    // Placing the order redirects to the backend-hosted payment page.
    const [saveOrder] = await Promise.all([
        page.waitForResponse((response) => response.url().includes('checkout/onepage/saveOrder')),
        page.getByRole('button', { name: 'Place Order' }).click(),
    ]);
    const saved = (await saveOrder.json()) as { success?: boolean; error_messages?: string; redirect?: string };
    expect(saved.error_messages, 'checkout accepted the order').toBeUndefined();
    expect(saved.redirect).toContain('taler/payment/redirect');
    await page.waitForURL(/\/orders\/[^/?]+\?token=/, { timeout: 60_000 });

    const payUri = (await page.content()).match(/taler:\/\/pay\/[^"'<\s]+/)?.[0];
    expect(payUri, 'the payment page shows a taler://pay URI').toBeTruthy();

    // handle-uri waits for the payment to finish.
    wallet('handle-uri', '-y', payUri!.replaceAll('&amp;', '&'));

    // The backend page long-polls, then redirects to the fulfillment URL;
    // the module verifies the payment server-side and shows the success page.
    await page.waitForURL(/checkout\/onepage\/success/, { timeout: 90_000 });

    await expect(page.getByRole('heading', { name: 'Your order has been received.' })).toBeVisible();
    incrementId = (await page.getByText(/Your order # is:/).textContent())?.match(/\d+/)?.[0];
    expect(incrementId, 'the success page shows the order number').toBeTruthy();

    const order = inspect<OrderInfo>('order', incrementId!);
    expect(order.payment_method).toBe('taler');
    expect(order.currency).toBe('KUDOS');
    expect(order.taler_order_id).toBeTruthy();
    expect(order.taler_amount).toBe(`KUDOS:${order.grand_total.toFixed(2)}`);
    expect(order.state).toBe('processing');
    expect(order.invoices).toBe(1);
    expect(order.total_paid).toBe(order.grand_total);
});

test('online credit memos from the admin refund the order in the Taler backend, partially and then fully', async ({ page }) => {
    expect(incrementId, 'the checkout test paid an order').toBeTruthy();
    const paid = inspect<OrderInfo>('order', incrementId!);

    await page.goto('admin/');
    await page.locator('#username').fill(process.env.BROWSER_ADMIN_USER ?? 'admin');
    await page.locator('#login').fill(process.env.BROWSER_ADMIN_PASSWORD ?? 'AdminTest123456!');
    await page.locator('#login').press('Enter');
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();

    // Admin URLs carry a secret key, so reach the order through the menu and grid.
    const salesMenu = page.locator('li:has(> #nav-admin-sales)');
    await salesMenu.hover();
    await salesMenu.getByRole('link', { name: 'Orders', exact: true }).click();
    await page.locator('input[name="real_order_id"]').fill(incrementId!);
    await page.locator('input[name="real_order_id"]').press('Enter');
    await page.getByRole('row').filter({ hasText: incrementId! }).first().click();

    // Online refunds start from the invoice: the order's own Credit Memo
    // button makes an offline one for gateway methods.
    const newCreditMemoFromInvoice = async () => {
        await expect(page.locator('.head-sales-order')).toContainText(`Order # ${incrementId}`);
        await page.locator('#sales_order_view_tabs_order_invoices').click();
        await page.locator('#order_invoices_table tbody tr').first().click();
        await page.getByRole('button', { name: 'Credit Memo', exact: true }).click();
        await expect(page.getByRole('heading', { name: /New Credit Memo for Invoice/ })).toBeVisible();
    };

    // First credit memo: the item only, keeping the shipping back.
    await newCreditMemoFromInvoice();
    await page.locator('#shipping_amount').fill('0');
    await page.getByRole('button', { name: 'Update Totals' }).click();
    await page.getByRole('button', { name: 'Refund', exact: true }).click();
    await expect(page.getByText('The credit memo has been created.')).toBeVisible();

    const partial = inspect<OrderInfo>('order', incrementId!);
    const itemTotal = paid.grand_total - 0.5;
    expect(partial.creditmemos).toBe(1);
    expect(partial.total_refunded).toBe(itemTotal);
    expect(partial.state).not.toBe('closed');
    expect(partial.backend?.refunded).toBe(true);
    expect(talerValue(partial.backend?.refund_amount)).toBe(itemTotal);

    // Second credit memo: what is left (the shipping). Taler takes the new
    // cumulative total, so the backend must now report the full amount.
    await newCreditMemoFromInvoice();
    await page.getByRole('button', { name: 'Refund', exact: true }).click();
    await expect(page.getByText('The credit memo has been created.')).toBeVisible();

    const full = inspect<OrderInfo>('order', incrementId!);
    expect(full.creditmemos).toBe(2);
    expect(full.total_refunded).toBe(paid.grand_total);
    expect(full.state).toBe('closed');
    expect(talerValue(full.backend?.refund_amount)).toBe(paid.grand_total);
});
