const { test, expect } = require('@playwright/test');

async function loginToAdminAgent(page) {
    await page.goto('/wp-admin/options-general.php?page=wp-admin-agent', { waitUntil: 'networkidle' });

    if (page.url().includes('wp-login.php')) {
        await page.fill('#user_login', 'admin');
        await page.fill('#user_pass', 'password');
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('#wp-submit'),
        ]);
    }

    await expect(page).toHaveURL(/page=wp-admin-agent/);
    await page.getByRole('button', { name: /open ai assistant/i }).click();
}

function buildSse(events) {
    return events.map((event) => `data: ${JSON.stringify(event)}\n\n`).join('') + 'data: [DONE]\n\n';
}

test.describe('runtime hallucination guards', () => {
    test('shows a failure warning when assistant text overclaims a failed tool result', async ({ page }) => {
        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();

            const events = body.message === 'Install WooCommerce'
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    { type: 'tool_start', tool_name: 'install_plugin', tool_use_id: 'tc_fail', tool_input: { slug: 'woocommerce' } },
                    { type: 'tool_end', tool_name: 'install_plugin', tool_use_id: 'tc_fail', result: { success: false, error: 'Download failed.' } },
                    { type: 'text_delta', content: 'Confirmed. Plugin installation completed.' },
                    { type: 'done' },
                ]
                : [
                    { type: 'error', message: `Unexpected body: ${JSON.stringify(body)}` },
                    { type: 'done' },
                ];

            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream; charset=utf-8',
                headers: { 'cache-control': 'no-store' },
                body: buildSse(events),
            });
        });

        await loginToAdminAgent(page);

        await page.getByLabel('Message input').fill('Install WooCommerce');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Confirmed. Plugin installation completed.')).toBeVisible();
        await expect(page.getByText('One or more tool steps failed. Review the error details above.')).toBeVisible();
    });

    test('shows a queued warning when assistant text overclaims an async result', async ({ page }) => {
        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();

            const events = body.message === 'Run a Wordfence scan'
                ? [
                    { type: 'usage', input_tokens: 6, output_tokens: 2, cost_usd: 0.01 },
                    { type: 'tool_start', tool_name: 'wordfence_run_scan', tool_use_id: 'tc_scan', tool_input: {} },
                    {
                        type: 'tool_end',
                        tool_name: 'wordfence_run_scan',
                        tool_use_id: 'tc_scan',
                        result: {
                            success: true,
                            async: true,
                            job_type: 'wordfence_scan',
                            job_status: 'queued',
                            recommended_poll_tool: 'wordfence_get_scan_results',
                            recommended_delay_sec: 60,
                            message: 'Wordfence scan scheduled and waiting for WP-Cron.',
                        },
                    },
                    { type: 'text_delta', content: 'Done. The scan is complete.' },
                    { type: 'done' },
                ]
                : [
                    { type: 'error', message: `Unexpected body: ${JSON.stringify(body)}` },
                    { type: 'done' },
                ];

            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream; charset=utf-8',
                headers: { 'cache-control': 'no-store' },
                body: buildSse(events),
            });
        });

        await loginToAdminAgent(page);

        await page.getByLabel('Message input').fill('Run a Wordfence scan');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Done. The scan is complete.')).toBeVisible();
        await expect(page.getByText('The requested action has only been queued so far and is still waiting to finish in the background.')).toBeVisible();
        await expect(page.getByText('Background task')).toBeVisible();
        await expect(page.getByText('Follow-up: wordfence_get_scan_results in about 60 seconds.')).toBeVisible();
    });
});
