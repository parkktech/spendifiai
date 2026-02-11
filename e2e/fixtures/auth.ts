import { test as base, Page } from '@playwright/test';

type AuthFixtures = {
    authenticatedPage: Page;
};

export const test = base.extend<AuthFixtures>({
    authenticatedPage: async ({ page }, use) => {
        const uniqueEmail = `e2e-${Date.now()}@test.com`;

        await page.goto('/register');
        await page.fill('[name="name"]', 'E2E Test User');
        await page.fill('[name="email"]', uniqueEmail);
        await page.fill('[name="password"]', 'TestPassword123!');
        await page.fill('[name="password_confirmation"]', 'TestPassword123!');
        await page.click('button[type="submit"]');

        await page.waitForURL('**/dashboard', { timeout: 10000 });

        await use(page);
    },
});

export { expect } from '@playwright/test';
