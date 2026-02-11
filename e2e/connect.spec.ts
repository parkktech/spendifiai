import { test, expect } from './fixtures/auth';

test.describe('Connect', () => {
    test('connect page loads with Plaid link button', async ({ authenticatedPage: page }) => {
        await page.goto('/connect');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/connect/);

        await page.screenshot({
            path: `test-results/screenshots/connect-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
