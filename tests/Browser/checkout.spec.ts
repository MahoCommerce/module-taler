import { execFileSync } from 'node:child_process';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { expect, test, type Locator, type Page } from '@playwright/test';

/**
 * End-to-end storefront test: a guest buys the product created by setup.php,
 * picks GNU Taler, lands on the store payment page, and a headless wallet
 * (taler-wallet-cli) pays the taler://pay URI shown there, like a phone
 * wallet scanning the QR code. The page polls the store, which sends the
 * browser to the success page, and the order must end up invoiced. A second
 * checkout does the same through the payment page of the merchant backend.
 *
 * Env: TALER_WALLET_CLI (wallet binary, default taler-wallet-cli),
 * TALER_WALLET_BANK_URL / TALER_WALLET_EXCHANGE_URL (withdraw endpoints),
 * TALER_SCREENSHOTS_DIR (save the README screenshots there).
 */

const repoRoot = fileURLToPath(new URL('../..', import.meta.url));
const screenshotsDir = process.env.TALER_SCREENSHOTS_DIR;

/**
 * Saves a README screenshot of the area that covers all the given elements,
 * on a light frame so it stands out from the README text.
 */
async function screenshot(page: Page, name: string, areas: Locator[], margin = 16): Promise<void> {
    if (!screenshotsDir) {
        return;
    }
    await areas[0].scrollIntoViewIfNeeded();
    const boxes = await Promise.all(areas.map((area) => area.boundingBox()));
    const viewport = page.viewportSize()!;
    const left = Math.max(0, Math.min(...boxes.map((box) => box!.x)) - margin);
    const top = Math.max(0, Math.min(...boxes.map((box) => box!.y)) - margin);
    const right = Math.min(viewport.width, Math.max(...boxes.map((box) => box!.x + box!.width)) + margin);
    const bottom = Math.min(viewport.height, Math.max(...boxes.map((box) => box!.y + box!.height)) + margin);
    const width = Math.round(right - left);
    const shot = await page.screenshot({
        clip: { x: left, y: top, width, height: bottom - top },
        animations: 'disabled',
    });

    const frame = await page.context().newPage();
    await frame.setContent(`<body style="margin:0">
        <div id="frame" style="display:inline-block;padding:24px;background:#eef1f5;border-radius:12px">
            <img src="data:image/png;base64,${shot.toString('base64')}"
                style="display:block;width:${width}px;border-radius:6px;box-shadow:0 2px 12px rgba(0,0,0,.18)">
        </div>
    </body>`);
    await frame.locator('#frame').screenshot({ path: resolve(screenshotsDir, `${name}.png`), omitBackground: true });
    await frame.close();
}

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

function inspect<T>(command: 'product' | 'order' | 'config', argument: string): T {
    const json = execFileSync('php', ['tests/Browser/inspect.php', command, argument], { cwd: repoRoot, encoding: 'utf8' });
    return JSON.parse(json) as T;
}

// The refund test works on the order the checkout test paid.
test.describe.configure({ mode: 'serial' });

let incrementId: string | undefined;

/**
 * Fills the one-page checkout as a guest up to the point where the order
 * can be placed with GNU Taler.
 */
async function checkoutAsGuest(page: Page): Promise<void> {
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
}

/** Checks the success page and the invoiced order, and returns its increment id. */
async function expectInvoicedOrder(page: Page): Promise<string> {
    await expect(page.getByRole('heading', { name: 'Your order has been received.' })).toBeVisible();
    const paidIncrementId = (await page.getByText(/Your order # is:/).textContent())?.match(/\d+/)?.[0];
    expect(paidIncrementId, 'the success page shows the order number').toBeTruthy();

    const order = inspect<OrderInfo>('order', paidIncrementId!);
    expect(order.payment_method).toBe('taler');
    expect(order.currency).toBe('KUDOS');
    expect(order.taler_order_id).toBeTruthy();
    expect(order.taler_amount).toBe(`KUDOS:${order.grand_total.toFixed(2)}`);
    expect(order.state).toBe('processing');
    expect(order.invoices).toBe(1);
    expect(order.total_paid).toBe(order.grand_total);
    return paidIncrementId!;
}

test('a guest pays with GNU Taler on the store page and comes back to an invoiced order', async ({ page }) => {
    fundWallet('KUDOS:5');
    await checkoutAsGuest(page);

    await expect(page.getByText('Pay with digital cash from your GNU Taler wallet.')).toBeVisible();
    await screenshot(page, 'checkout', [page.locator('#onestep-payment'), page.locator('#onestep-review')]);

    // Placing the order opens the store payment page.
    await page.getByRole('button', { name: 'Place Order' }).click();
    await page.waitForURL(/\/taler\/payment\/pay/, { timeout: 60_000 });

    const payLink = page.getByRole('link', { name: 'Open in wallet' });
    const payUri = await payLink.getAttribute('href');
    expect(payUri, 'the payment page links a taler://pay URI').toMatch(/^taler:\/\/pay\//);
    await expect(page.locator('.taler-qr-code svg')).toBeVisible();
    await screenshot(page, 'payment-page', [page.locator('.taler-payment')], 40);

    // handle-uri waits for the payment to finish, as a phone wallet that
    // scans the QR code would.
    wallet('handle-uri', '-y', payUri!);

    // The page polls the store, which checks the backend and then opens
    // the success page.
    await page.waitForURL(/checkout\/onepage\/success/, { timeout: 90_000 });
    incrementId = await expectInvoicedOrder(page);
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
    // Update Totals reloads the totals block, including the Refund button.
    await page.waitForLoadState('networkidle');
    await page.getByRole('button', { name: 'Refund', exact: true }).scrollIntoViewIfNeeded();
    const refundTotals = page.locator('.entry-edit:has(> .entry-edit-head h4:text-is("Refund Totals"))');
    if (screenshotsDir) {
        // The box fills half the page with the totals on its right; shrink it to them.
        await refundTotals.evaluate((box: HTMLElement) => {
            box.style.width = 'max-content';
            box.querySelectorAll('table').forEach((table) => (table.style.width = 'auto'));
        });
    }
    await screenshot(page, 'admin-credit-memo', [refundTotals]);
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

    await screenshot(page, 'admin-refund-history', [page.locator('#order_history_block .note-list')]);

    const full = inspect<OrderInfo>('order', incrementId!);
    expect(full.creditmemos).toBe(2);
    expect(full.total_refunded).toBe(paid.grand_total);
    expect(full.state).toBe('closed');
    expect(talerValue(full.backend?.refund_amount)).toBe(paid.grand_total);
});

test('with the backend payment page setting, the guest pays on the page of the Taler backend', async ({ page }) => {
    fundWallet('KUDOS:5');
    inspect('config', 'payment/taler/payment_page=backend');
    try {
        await checkoutAsGuest(page);
        await expect(page.getByText('you go to the GNU Taler payment page')).toBeVisible();

        await page.getByRole('button', { name: 'Place Order' }).click();
        await page.waitForURL(/\/orders\/[^/?]+\?token=/, { timeout: 60_000 });

        const payUri = (await page.content()).match(/taler:\/\/pay\/[^"'<\s]+/)?.[0];
        expect(payUri, 'the backend payment page shows a taler://pay URI').toBeTruthy();
        wallet('handle-uri', '-y', payUri!.replaceAll('&amp;', '&'));

        // The backend page long-polls, then opens the fulfillment URL; the
        // module checks the payment on the server and shows the success page.
        await page.waitForURL(/checkout\/onepage\/success/, { timeout: 90_000 });
        await expectInvoicedOrder(page);
    } finally {
        inspect('config', 'payment/taler/payment_page=store');
    }
});

test('the cancel button deletes the order in the backend and gives the cart back', async ({ page }) => {
    await checkoutAsGuest(page);
    await page.getByRole('button', { name: 'Place Order' }).click();
    await page.waitForURL(/\/taler\/payment\/pay/, { timeout: 60_000 });
    const cancelledIncrementId = (await page.getByText(/^Order #/).textContent())?.match(/#(\S+):/)?.[1];
    expect(cancelledIncrementId, 'the payment page shows the order number').toBeTruthy();

    // The config write flushes the cache, which also resets the rate limit counters.
    inspect('config', 'system/rate_limit/taler_payment_status=1');
    try {
        await page.request.get('/taler/payment/status', { timeout: 15_000 });
        const start = Date.now();
        const limited = await page.request.get('/taler/payment/status');
        expect((await limited.json()).done).toBe(false);
        expect(Date.now() - start, 'a limited poll does not wait for the backend').toBeLessThan(5_000);
    } finally {
        inspect('config', 'system/rate_limit/taler_payment_status=30');
    }

    await page.getByRole('button', { name: 'Cancel and return to cart' }).click();
    await page.waitForURL(/checkout\/cart/);
    await expect(page.locator('#shopping-cart-table')).toContainText('Taler Browser Test Product');

    const order = inspect<OrderInfo>('order', cancelledIncrementId!);
    expect(order.state).toBe('canceled');
    expect(order.backend, 'the backend no longer knows the order').toBeNull();
});

test('an order that is paid before the page sees it opens the success page, also from the cancel button', async ({ page }) => {
    fundWallet('KUDOS:5');
    await checkoutAsGuest(page);
    // Stop the page polling, so that the order is paid while the page still shows the QR code.
    await page.route('**/taler/payment/status**', (route) => route.abort());
    await page.getByRole('button', { name: 'Place Order' }).click();
    await page.waitForURL(/\/taler\/payment\/pay/, { timeout: 60_000 });
    wallet('handle-uri', '-y', (await page.getByRole('link', { name: 'Open in wallet' }).getAttribute('href'))!);
    await expect.poll(
        async () => (await (await page.request.get('/taler/payment/status')).json()).done,
        { timeout: 60_000 },
    ).toBe(true);

    // The canonical URL, with the trailing slash that the store adds: other forms get a 301 first.
    const pay = await page.request.get('taler/payment/pay/', { maxRedirects: 0 });
    expect(pay.status()).toBe(302);
    expect(pay.headers()['location']).toMatch(/taler\/payment\/success/);

    await page.getByRole('button', { name: 'Cancel and return to cart' }).click();
    await page.waitForURL(/checkout\/onepage\/success/);
    await expectInvoicedOrder(page);
});
