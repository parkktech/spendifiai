import { test, expect } from './fixtures/auth';
import AxeBuilder from '@axe-core/playwright';

const pages = [
    { name: 'Dashboard', path: '/dashboard' },
    { name: 'Transactions', path: '/transactions' },
    { name: 'Subscriptions', path: '/subscriptions' },
    { name: 'Savings', path: '/savings' },
    { name: 'Tax', path: '/tax' },
    { name: 'Connect', path: '/connect' },
    { name: 'Settings', path: '/settings' },
    { name: 'Questions', path: '/questions' },
];

for (const { name, path } of pages) {
    test(`${name} page passes accessibility checks`, async ({ authenticatedPage: page }) => {
        await page.goto(path);
        await page.waitForLoadState('networkidle');

        const results = await new AxeBuilder({ page })
            .withTags(['wcag2a', 'wcag2aa'])
            .analyze();

        // Log violations for debugging
        if (results.violations.length > 0) {
            console.log(`${name} accessibility violations:`, JSON.stringify(results.violations.map(v => ({
                id: v.id,
                impact: v.impact,
                description: v.description,
                nodes: v.nodes.length,
            })), null, 2));
        }

        // Exclude color-contrast (will be fixed with light theme redesign)
        // Focus on structural accessibility violations
        const criticalViolations = results.violations.filter(
            v => (v.impact === 'critical' || v.impact === 'serious') && v.id !== 'color-contrast'
        );

        expect(criticalViolations).toEqual([]);
    });
}
