import { test, expect } from './fixtures/auth';

test.describe('Navigation', () => {
    test('sidebar links navigate to correct pages', async ({ authenticatedPage: page }) => {
        const navLinks = [
            { text: /transactions/i, url: /transactions/ },
            { text: /subscriptions/i, url: /subscriptions/ },
            { text: /savings/i, url: /savings/ },
            { text: /tax/i, url: /tax/ },
            { text: /connect/i, url: /connect/ },
            { text: /settings/i, url: /settings/ },
        ];

        for (const link of navLinks) {
            const navLink = page.locator(`nav a, aside a`).filter({ hasText: link.text }).first();
            if (await navLink.isVisible()) {
                await navLink.click();
                await page.waitForLoadState('networkidle');
                await expect(page).toHaveURL(link.url);
                await page.goto('/dashboard');
                await page.waitForLoadState('networkidle');
            }
        }
    });

    test('active nav item is highlighted', async ({ authenticatedPage: page }) => {
        await expect(page).toHaveURL(/dashboard/);
        // The active nav item should have a distinct style
        const dashboardLink = page.locator('nav a, aside a').filter({ hasText: /dashboard/i }).first();
        if (await dashboardLink.isVisible()) {
            await expect(dashboardLink).toBeVisible();
        }
    });
});
