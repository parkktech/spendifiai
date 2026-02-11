import { test, expect } from './fixtures/auth';

test.describe('AI Questions', () => {
    test('questions page loads', async ({ authenticatedPage: page }) => {
        await page.goto('/questions');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveURL(/questions/);

        await page.screenshot({
            path: `test-results/screenshots/questions-${test.info().project.name}.png`,
            fullPage: true,
        });
    });
});
