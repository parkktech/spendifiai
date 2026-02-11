import { test, expect } from './fixtures/auth';

test.describe('Settings', () => {
    test('settings page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/settings');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/settings/);

        await page.screenshot({
            path: `test-results/screenshots/settings-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
