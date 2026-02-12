import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: [
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
        ['list'],
    ],
    timeout: 60000,
    use: {
        baseURL: process.env.APP_URL || 'http://www.ledgeriq.loc',
        actionTimeout: 15000,
        trace: 'on-first-retry',
        screenshot: 'on',
        video: 'on-first-retry',
    },
    projects: [
        {
            name: 'chromium-desktop',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'mobile-chrome',
            use: { ...devices['Pixel 7'] },
        },
        {
            name: 'mobile-safari',
            use: { ...devices['iPhone 14'] },
        },
        {
            name: 'tablet',
            use: { ...devices['iPad (gen 7)'] },
        },
    ],
    webServer: process.env.CI
        ? {
              command: 'php artisan serve --port=8000',
              url: 'http://localhost:8000',
              reuseExistingServer: false,
              timeout: 30000,
          }
        : undefined,
});
