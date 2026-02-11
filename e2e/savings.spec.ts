import { test, expect } from './fixtures/auth';

test.describe('Savings', () => {
    test('savings page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/savings');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/savings/);

        await page.screenshot({
            path: `test-results/screenshots/savings-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
