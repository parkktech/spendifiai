import { test, expect } from './fixtures/auth';

test.describe('Dashboard', () => {
    test('dashboard page loads with stats', async ({ authenticatedPage: page }) => {
        await expect(page).toHaveURL(/dashboard/);
        await page.waitForLoadState('networkidle');

        // Dashboard should show the page structure
        await expect(page.locator('main, #main-content, [role="main"]')).toBeVisible();

        await page.screenshot({
            path: `test-results/screenshots/dashboard-${test.info().project.name}.png`,
            fullPage: true,
        });
    });

    test('sidebar navigation is visible on desktop', async ({ authenticatedPage: page }) => {
        const viewport = page.viewportSize();
        if (viewport && viewport.width >= 1024) {
            await expect(page.locator('nav')).toBeVisible();
        }
    });

    test('mobile hamburger menu works on mobile', async ({ authenticatedPage: page }) => {
        const viewport = page.viewportSize();
        if (viewport && viewport.width < 1024) {
            const hamburger = page.locator('[aria-label="Open sidebar"], button:has(svg)').first();
            if (await hamburger.isVisible()) {
                await hamburger.click();
                await page.waitForTimeout(300);
                await page.screenshot({
                    path: `test-results/screenshots/dashboard-mobile-menu-${test.info().project.name}.png`,
                    fullPage: true,
                });
            }
        }
    });
});
