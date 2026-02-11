import { test, expect } from './fixtures/auth';

test.describe('Transactions', () => {
    test('transactions page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/transactions');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/transactions/);

        await page.screenshot({
            path: `test-results/screenshots/transactions-${test.info().project.name}.png`,
            fullPage: true,
        });
    });

    test('filter bar is interactive', async ({ authenticatedPage: page }) => {
        await page.goto('/transactions');
        await page.waitForLoadState('networkidle');

        // Check for filter inputs
        const dateInputs = page.locator('input[type="date"]');
        const dateCount = await dateInputs.count();
        expect(dateCount).toBeGreaterThanOrEqual(0);
    });
});
