const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    fullyParallel: false,
    use: {
        baseURL: 'http://localhost:8888',
        headless: true,
        trace: 'on-first-retry',
    },
});
