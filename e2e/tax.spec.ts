import { test, expect } from './fixtures/auth';

test.describe('Tax', () => {
    test('tax page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/tax');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/tax/);

        await page.screenshot({
            path: `test-results/screenshots/tax-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
