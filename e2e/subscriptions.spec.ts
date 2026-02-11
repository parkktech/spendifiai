import { test, expect } from './fixtures/auth';

test.describe('Subscriptions', () => {
    test('subscriptions page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/subscriptions');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/subscriptions/);

        await page.screenshot({
            path: `test-results/screenshots/subscriptions-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
