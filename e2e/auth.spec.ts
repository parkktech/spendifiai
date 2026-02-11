import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('register page renders', async ({ page }) => {
        await page.goto('/register');
        await expect(page.locator('button[type="submit"]')).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-register-${test.info().project.name}.png`, fullPage: true });
    });

    test('login page renders', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('button[type="submit"]')).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-login-${test.info().project.name}.png`, fullPage: true });
    });

    test('user can register', async ({ page }) => {
        const email = `e2e-register-${Date.now()}@test.com`;

        await page.goto('/register');
        await page.fill('[name="name"]', 'Test User');
        await page.fill('[name="email"]', email);
        await page.fill('[name="password"]', 'TestPassword123!');
        await page.fill('[name="password_confirmation"]', 'TestPassword123!');
        await page.click('button[type="submit"]');

        await page.waitForURL('**/dashboard', { timeout: 10000 });
        await expect(page).toHaveURL(/dashboard/);
    });

    test('user can login and logout', async ({ page }) => {
        const email = `e2e-login-${Date.now()}@test.com`;

        // Register first
        await page.goto('/register');
        await page.fill('[name="name"]', 'Login Test');
        await page.fill('[name="email"]', email);
        await page.fill('[name="password"]', 'TestPassword123!');
        await page.fill('[name="password_confirmation"]', 'TestPassword123!');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/dashboard', { timeout: 10000 });

        // Logout
        await page.goto('/logout', { waitUntil: 'networkidle' });
    });

    test('login fails with wrong password', async ({ page }) => {
        await page.goto('/login');
        await page.fill('[name="email"]', 'nonexistent@test.com');
        await page.fill('[name="password"]', 'wrongpassword');
        await page.click('button[type="submit"]');

        await page.waitForTimeout(1000);
        const errorVisible = await page.locator('.text-red-600, .text-destructive, [role="alert"]').isVisible();
        expect(errorVisible).toBeTruthy();
    });

    test('forgot password page renders', async ({ page }) => {
        await page.goto('/forgot-password');
        await expect(page.locator('button[type="submit"]')).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-forgot-password-${test.info().project.name}.png`, fullPage: true });
    });
});
