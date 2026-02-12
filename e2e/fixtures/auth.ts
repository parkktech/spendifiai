import { test as base, Page } from '@playwright/test';

type AuthFixtures = {
    authenticatedPage: Page;
};

export const test = base.extend<AuthFixtures>({
    authenticatedPage: async ({ page }, use) => {
        // Login with pre-created demo user (email already verified)
        await page.goto('/login');
        await page.fill('[name="email"]', 'demo@ledgeriq.loc');
        await page.fill('[name="password"]', 'Demo1234!');
        await page.getByRole('button', { name: /log in/i }).click();

        await page.waitForURL('**/dashboard', { timeout: 15000 });

        await use(page);
    },
});

export { expect } from '@playwright/test';
