import { test, expect } from '@playwright/test';

test.describe('Authentication', () => {
    test('register page renders', async ({ page }) => {
        await page.goto('/register');
        await expect(page.getByRole('button', { name: /register/i })).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-register-${test.info().project.name}.png`, fullPage: true });
    });

    test('login page renders', async ({ page }) => {
        await page.goto('/login');
        await expect(page.getByRole('button', { name: /log in/i })).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-login-${test.info().project.name}.png`, fullPage: true });
    });

    test('user can register', async ({ page }) => {
        const email = `e2e-register-${Date.now()}-${Math.random().toString(36).slice(2)}@test.com`;

        await page.goto('/register');
        await page.fill('[name="name"]', 'Test User');
        await page.fill('[name="email"]', email);
        await page.fill('[name="password"]', 'TestPassword123!');
        await page.fill('[name="password_confirmation"]', 'TestPassword123!');
        await page.getByRole('button', { name: /register/i }).click();

        // MustVerifyEmail redirects to /verify-email after registration
        await page.waitForURL('**/verify-email', { timeout: 15000 });
        await expect(page).toHaveURL(/verify-email/);
    });

    test('user can login and logout', async ({ page }) => {
        // Login with pre-created demo user (email already verified)
        await page.goto('/login');
        await page.fill('[name="email"]', 'demo@spendifiai.loc');
        await page.fill('[name="password"]', 'Demo1234!');
        await page.getByRole('button', { name: /log in/i }).click();
        await page.waitForURL('**/dashboard', { timeout: 15000 });

        // Logout by clearing cookies (POST /logout is stateless API, doesn't clear browser session)
        await page.context().clearCookies();
        await page.goto('/login');
        await expect(page).toHaveURL(/login/);
    });

    test('login fails with wrong password', async ({ page }) => {
        await page.goto('/login');
        await page.fill('[name="email"]', 'nonexistent@test.com');
        await page.fill('[name="password"]', 'wrongpassword');
        await page.getByRole('button', { name: /log in/i }).click();

        await page.waitForTimeout(2000);
        const errorVisible = await page.locator('.text-red-600, .text-destructive, [role="alert"], .mt-2').isVisible();
        expect(errorVisible).toBeTruthy();
    });

    test('forgot password page renders', async ({ page }) => {
        await page.goto('/forgot-password');
        await expect(page.getByRole('button', { name: /email password reset link/i })).toBeVisible();
        await page.screenshot({ path: `test-results/screenshots/auth-forgot-password-${test.info().project.name}.png`, fullPage: true });
    });
});
