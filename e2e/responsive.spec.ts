import { test, expect } from './fixtures/auth';

const pages = [
    { name: 'Dashboard', path: '/dashboard' },
    { name: 'Transactions', path: '/transactions' },
    { name: 'Subscriptions', path: '/subscriptions' },
    { name: 'Savings', path: '/savings' },
    { name: 'Tax', path: '/tax' },
    { name: 'Connect', path: '/connect' },
    { name: 'Settings', path: '/settings' },
    { name: 'Questions', path: '/questions' },
];

for (const { name, path } of pages) {
    test(`${name} responsive screenshot`, async ({ authenticatedPage: page }) => {
        await page.goto(path);
        await page.waitForLoadState('networkidle');

        await page.screenshot({
            path: `test-results/screenshots/${name.toLowerCase()}-${test.info().project.name}.png`,
            fullPage: true,
        });

        // Verify no horizontal overflow
        const bodyWidth = await page.evaluate(() => document.body.scrollWidth);
        const viewportWidth = page.viewportSize()?.width ?? 1280;
        expect(bodyWidth).toBeLessThanOrEqual(viewportWidth + 20); // 20px tolerance
    });
}
