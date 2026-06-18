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

test.describe('destructive action confirmation flow', () => {
    test('replays a destructive action only after the user confirms it', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            let events;

            if (body.message === 'Deactivate Hello Dolly' && !body.confirmation) {
                events = [
                    { type: 'usage', input_tokens: 8, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'deactivate_plugin',
                        tool_use_id: 'tc_confirm',
                        tool_input: { plugin_file: 'hello-dolly/hello.php' },
                        message: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
                    },
                    { type: 'done' },
                ];
            } else if (body.message === 'Yes, proceed.') {
                events = [
                    {
                        type: 'tool_start',
                        tool_name: 'deactivate_plugin',
                        tool_use_id: 'tc_confirm',
                        tool_input: { plugin_file: 'hello-dolly/hello.php' },
                    },
                    {
                        type: 'tool_end',
                        tool_name: 'deactivate_plugin',
                        tool_use_id: 'tc_confirm',
                        result: { plugin: 'hello-dolly/hello.php', success: true },
                    },
                    { type: 'text_delta', content: 'Confirmed. The plugin `hello-dolly/hello.php` has been deactivated.' },
                    { type: 'done' },
                ];
            } else {
                events = [
                    { type: 'error', message: `Unexpected body: ${JSON.stringify(body)}` },
                    { type: 'done' },
                ];
            }

            await route.fulfill({
                status: 200,
                contentType: 'text/event-stream; charset=utf-8',
                headers: { 'cache-control': 'no-store' },
                body: buildSse(events),
            });
        });

        await loginToAdminAgent(page);

        await page.getByLabel('Message input').fill('Deactivate Hello Dolly');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(page.getByText('Nothing will change until you confirm.')).toBeVisible();
        await expect(page.locator('[class*=confirmationHint]')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Confirm change' })).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. The plugin `hello-dolly/hello.php` has been deactivated.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'deactivate_plugin',
            tool_use_id: 'tc_confirm',
            tool_input: { plugin_file: 'hello-dolly/hello.php' },
        });
    });

    test('records a cancellation locally without replaying the pending action', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Switch to Astra' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 7, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'switch_theme',
                        tool_use_id: 'tc_theme',
                        tool_input: { theme_slug: 'astra' },
                        message: 'This will switch the active theme to `astra`. Confirm to continue.',
                    },
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

        await page.getByLabel('Message input').fill('Switch to Astra');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Approve change')).toBeVisible();
        await page.getByRole('button', { name: 'Cancel action' }).click();

        await expect(page.getByText('Okay, I will not run that action.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(1);
    });

    test('requires confirmation before installing a plugin', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Install WooCommerce' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'install_plugin',
                        tool_use_id: 'tc_install_plugin',
                        tool_input: { slug: 'woocommerce' },
                        message: 'This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'install_plugin',
                            tool_use_id: 'tc_install_plugin',
                            tool_input: { slug: 'woocommerce' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'install_plugin',
                            tool_use_id: 'tc_install_plugin',
                            result: { plugin_file: 'woocommerce/woocommerce.php', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Plugin `woocommerce/woocommerce.php` has been installed and is ready to activate.' },
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

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Plugin `woocommerce/woocommerce.php` has been installed and is ready to activate.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'install_plugin',
            tool_use_id: 'tc_install_plugin',
            tool_input: { slug: 'woocommerce' },
        });
    });

    test('requires confirmation before installing a theme', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Install Astra theme' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'install_theme',
                        tool_use_id: 'tc_install_theme',
                        tool_input: { slug: 'astra' },
                        message: 'This will install theme `astra`. It will not be activated automatically. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'install_theme',
                            tool_use_id: 'tc_install_theme',
                            tool_input: { slug: 'astra' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'install_theme',
                            tool_use_id: 'tc_install_theme',
                            result: { theme_slug: 'astra', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Theme `astra` has been installed and is ready to activate.' },
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

        await page.getByLabel('Message input').fill('Install Astra theme');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will install theme `astra`. It will not be activated automatically. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Theme `astra` has been installed and is ready to activate.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'install_theme',
            tool_use_id: 'tc_install_theme',
            tool_input: { slug: 'astra' },
        });
    });

    test('requires confirmation before publishing content', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Publish the launch announcement' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 9, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'create_post',
                        tool_use_id: 'tc_publish_post',
                        tool_input: { title: 'Launch announcement', status: 'publish' },
                        message: 'This will publish post `Launch announcement`. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'create_post',
                            tool_use_id: 'tc_publish_post',
                            tool_input: { title: 'Launch announcement', status: 'publish' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'create_post',
                            tool_use_id: 'tc_publish_post',
                            result: { post_id: 321, status: 'publish', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. The post `321` is now `publish`.' },
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

        await page.getByLabel('Message input').fill('Publish the launch announcement');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will publish post `Launch announcement`. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. The post `321` is now `publish`.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'create_post',
            tool_use_id: 'tc_publish_post',
            tool_input: { title: 'Launch announcement', status: 'publish' },
        });
    });

    test('does not require confirmation when saving content as draft', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Save the release notes as draft'
                ? [
                    { type: 'usage', input_tokens: 7, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'tool_start',
                        tool_name: 'create_post',
                        tool_use_id: 'tc_draft_post',
                        tool_input: { title: 'Release notes', status: 'draft' },
                    },
                    {
                        type: 'tool_end',
                        tool_name: 'create_post',
                        tool_use_id: 'tc_draft_post',
                        result: { post_id: 654, status: 'draft', success: true },
                    },
                    { type: 'text_delta', content: 'Draft saved successfully.' },
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

        await page.getByLabel('Message input').fill('Save the release notes as draft');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Draft saved successfully.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(1);
        expect(chatBodies[0].confirmation).toBeNull();
    });

    test('requires confirmation before updating a post to publish', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Publish post 42 now' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_publish',
                        tool_input: { post_id: 42, status: 'publish' },
                        message: 'This will update post `42` and publish it. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_publish',
                            tool_input: { post_id: 42, status: 'publish' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_publish',
                            result: { post_id: 42, status: 'updated', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Post `42` has been updated.' },
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

        await page.getByLabel('Message input').fill('Publish post 42 now');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will update post `42` and publish it. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Post `42` has been updated.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'update_post',
            tool_use_id: 'tc_update_publish',
            tool_input: { post_id: 42, status: 'publish' },
        });
    });

    test('does not require confirmation for a normal post content edit', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Update post 42 intro paragraph'
                ? [
                    { type: 'usage', input_tokens: 6, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'tool_start',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_content',
                        tool_input: { post_id: 42, content: '<p>Updated intro paragraph.</p>' },
                    },
                    {
                        type: 'tool_end',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_content',
                        result: { post_id: 42, status: 'updated', success: true },
                    },
                    { type: 'text_delta', content: 'Post content updated successfully.' },
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

        await page.getByLabel('Message input').fill('Update post 42 intro paragraph');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Post content updated successfully.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(1);
        expect(chatBodies[0].confirmation).toBeNull();
    });

    test('requires confirmation for editing content on a published post', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Update the published post 42 intro' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_live_content',
                        tool_input: { post_id: 42, content: '<p>Updated intro paragraph.</p>' },
                        message: 'This will update live post `42`. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_live_content',
                            tool_input: { post_id: 42, content: '<p>Updated intro paragraph.</p>' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_live_content',
                            result: { post_id: 42, status: 'updated', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Post `42` has been updated.' },
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

        await page.getByLabel('Message input').fill('Update the published post 42 intro');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will update live post `42`. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Post `42` has been updated.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'update_post',
            tool_use_id: 'tc_update_live_content',
            tool_input: { post_id: 42, content: '<p>Updated intro paragraph.</p>' },
        });
    });

    test('does not require confirmation for editing content on a draft post', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Update the draft post 42 intro'
                ? [
                    { type: 'usage', input_tokens: 6, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'tool_start',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_draft_content',
                        tool_input: { post_id: 42, content: '<p>Updated draft intro.</p>' },
                    },
                    {
                        type: 'tool_end',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_draft_content',
                        result: { post_id: 42, status: 'updated', success: true },
                    },
                    { type: 'text_delta', content: 'Draft post content updated successfully.' },
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

        await page.getByLabel('Message input').fill('Update the draft post 42 intro');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Draft post content updated successfully.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(1);
        expect(chatBodies[0].confirmation).toBeNull();
    });

    test('requires confirmation before updating a post to private', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Make post 42 private' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_private',
                        tool_input: { post_id: 42, status: 'private' },
                        message: 'This will update post `42` and make it private. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_private',
                            tool_input: { post_id: 42, status: 'private' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_private',
                            result: { post_id: 42, status: 'updated', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Post `42` has been updated.' },
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

        await page.getByLabel('Message input').fill('Make post 42 private');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will update post `42` and make it private. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Post `42` has been updated.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'update_post',
            tool_use_id: 'tc_update_private',
            tool_input: { post_id: 42, status: 'private' },
        });
    });

    test('requires confirmation before moving a post to trash', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Move post 42 to trash' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'update_post',
                        tool_use_id: 'tc_update_trash',
                        tool_input: { post_id: 42, status: 'trash' },
                        message: 'This will update post `42` and move it to trash. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_trash',
                            tool_input: { post_id: 42, status: 'trash' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'update_post',
                            tool_use_id: 'tc_update_trash',
                            result: { post_id: 42, status: 'updated', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. Post `42` has been updated.' },
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

        await page.getByLabel('Message input').fill('Move post 42 to trash');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will update post `42` and move it to trash. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. Post `42` has been updated.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'update_post',
            tool_use_id: 'tc_update_trash',
            tool_input: { post_id: 42, status: 'trash' },
        });
    });

    test('requires confirmation before creating a private simple post', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Create a private quick note' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 7, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'create_simple_post',
                        tool_use_id: 'tc_simple_private',
                        tool_input: { title: 'Quick note', status: 'private' },
                        message: 'This will create a private post `Quick note`. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'create_simple_post',
                            tool_use_id: 'tc_simple_private',
                            tool_input: { title: 'Quick note', status: 'private' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'create_simple_post',
                            tool_use_id: 'tc_simple_private',
                            result: { post_id: 777, status: 'private', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. The post `777` is now `private`.' },
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

        await page.getByLabel('Message input').fill('Create a private quick note');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will create a private post `Quick note`. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. The post `777` is now `private`.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'create_simple_post',
            tool_use_id: 'tc_simple_private',
            tool_input: { title: 'Quick note', status: 'private' },
        });
    });

    test('requires confirmation before creating a rich post for publish', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Publish a rich AI recap post' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 10, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'create_rich_post',
                        tool_use_id: 'tc_rich_publish',
                        tool_input: { title: 'AI recap', status: 'publish' },
                        message: 'This will publish post `AI recap`. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'create_rich_post',
                            tool_use_id: 'tc_rich_publish',
                            tool_input: { title: 'AI recap', status: 'publish' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'create_rich_post',
                            tool_use_id: 'tc_rich_publish',
                            result: { post_id: 888, status: 'publish', success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. The post `888` is now `publish`.' },
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

        await page.getByLabel('Message input').fill('Publish a rich AI recap post');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will publish post `AI recap`. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. The post `888` is now `publish`.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'create_rich_post',
            tool_use_id: 'tc_rich_publish',
            tool_input: { title: 'AI recap', status: 'publish' },
        });
    });

    test('requires confirmation before changing the featured image on a published post', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Set a featured image on published post 42' && !body.confirmation
                ? [
                    { type: 'usage', input_tokens: 8, output_tokens: 3, cost_usd: 0.01 },
                    {
                        type: 'confirmation_required',
                        tool_name: 'set_post_image',
                        tool_use_id: 'tc_live_featured_image',
                        tool_input: { post_id: 42, attachment_id: 99, mode: 'featured' },
                        message: 'This will replace the featured image on live post `42`. Confirm to continue.',
                    },
                    { type: 'done' },
                ]
                : body.message === 'Yes, proceed.'
                    ? [
                        {
                            type: 'tool_start',
                            tool_name: 'set_post_image',
                            tool_use_id: 'tc_live_featured_image',
                            tool_input: { post_id: 42, attachment_id: 99, mode: 'featured' },
                        },
                        {
                            type: 'tool_end',
                            tool_name: 'set_post_image',
                            tool_use_id: 'tc_live_featured_image',
                            result: { post_id: 42, success: true },
                        },
                        { type: 'text_delta', content: 'Confirmed. The image for post `42` has been updated.' },
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

        await page.getByLabel('Message input').fill('Set a featured image on published post 42');
        await page.getByRole('button', { name: 'Send message' }).click();

        const confirmationStatus = page.getByRole('status');
        await expect(page.getByText('Approve change')).toBeVisible();
        await expect(confirmationStatus.getByText('This will replace the featured image on live post `42`. Confirm to continue.')).toBeVisible();

        await page.getByRole('button', { name: 'Confirm change' }).click();

        await expect(page.getByText('Confirmed. The image for post `42` has been updated.')).toBeVisible();
        expect(chatBodies).toHaveLength(2);
        expect(chatBodies[1].confirmation).toMatchObject({
            approved: true,
            tool_name: 'set_post_image',
            tool_use_id: 'tc_live_featured_image',
            tool_input: { post_id: 42, attachment_id: 99, mode: 'featured' },
        });
    });

    test('does not require confirmation before changing the image on a draft post', async ({ page }) => {
        const chatBodies = [];

        await page.route('**/wp-json/wp-admin-agent/v1/chat', async (route) => {
            const body = route.request().postDataJSON();
            chatBodies.push(body);

            const events = body.message === 'Add an image to draft post 42'
                ? [
                    { type: 'usage', input_tokens: 7, output_tokens: 2, cost_usd: 0.01 },
                    {
                        type: 'tool_start',
                        tool_name: 'set_post_image',
                        tool_use_id: 'tc_draft_image',
                        tool_input: { post_id: 42, attachment_id: 99, mode: 'append' },
                    },
                    {
                        type: 'tool_end',
                        tool_name: 'set_post_image',
                        tool_use_id: 'tc_draft_image',
                        result: { post_id: 42, success: true },
                    },
                    { type: 'text_delta', content: 'Draft post image updated successfully.' },
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

        await page.getByLabel('Message input').fill('Add an image to draft post 42');
        await page.getByRole('button', { name: 'Send message' }).click();

        await expect(page.getByText('Draft post image updated successfully.')).toBeVisible();
        await expect(page.getByText('Approve change')).toHaveCount(0);
        expect(chatBodies).toHaveLength(1);
        expect(chatBodies[0].confirmation).toBeNull();
    });
});
