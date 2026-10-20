import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.BROWSER_BASE_URL ?? 'http://127.0.0.1:8000/';
const port = new URL(baseURL).port || '80';

export default defineConfig({
    testDir: '.',
    // One real payment per run: the demo backend and the wallet are shared state.
    workers: 1,
    retries: 0,
    timeout: 180_000,
    expect: { timeout: 15_000 },
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        actionTimeout: 20_000,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                // Sharp README screenshots on high-density screens.
                ...(process.env.TALER_SCREENSHOTS_DIR ? { deviceScaleFactor: 2, viewport: { width: 1280, height: 1000 } } : {}),
            },
        },
    ],
    webServer: {
        command: `php maho serve ${port}`,
        cwd: '../..',
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
