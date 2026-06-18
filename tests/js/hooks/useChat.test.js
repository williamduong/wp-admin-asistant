import { act, renderHook, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const chatStreamMock = vi.fn();
const parseSSEMock = vi.fn();
const createConversationMock = vi.fn();
const updateConversationMock = vi.fn();

vi.mock('../../../src/lib/api', () => ({
    chatStream: (...args) => chatStreamMock(...args),
    createConversation: (...args) => createConversationMock(...args),
    updateConversation: (...args) => updateConversationMock(...args),
}));

vi.mock('../../../src/lib/sse', () => ({
    parseSSE: (...args) => parseSSEMock(...args),
}));

describe('useChat', () => {
    beforeEach(() => {
        localStorage.clear();
        chatStreamMock.mockReset();
        parseSSEMock.mockReset();
        createConversationMock.mockReset();
        updateConversationMock.mockReset();
    });

    it('hydrates from localStorage and clears browser-owned session state', async () => {
        localStorage.setItem('waa_chat_v1', JSON.stringify({
            messages: [{ role: 'user', content: 'Saved message', id: 'saved-1' }],
            history: [{ role: 'user', content: 'Saved API history' }],
            usage: { input_tokens: 3, output_tokens: 4, cost_usd: 0.12, elapsed_ms: 50 },
        }));

        const { result } = await importHook();

        expect(result.current.messages).toHaveLength(1);
        expect(result.current.messages[0].content).toBe('Saved message');
        expect(result.current.sessionUsage.input_tokens).toBe(3);

        act(() => {
            result.current.clearMessages();
        });

        expect(result.current.messages).toEqual([]);
        expect(result.current.sessionUsage.input_tokens).toBe(0);
        expect(localStorage.getItem('waa_chat_v1')).toBeNull();
    });

    it('starts the predefined workflow from a slash command without calling the provider', async () => {
        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('/woo-setup');
        });

        expect(chatStreamMock).not.toHaveBeenCalled();
        expect(result.current.activeWorkflow).toMatchObject({
            kind: 'wizard',
            workflowId: 'woocommerce_first_time_setup',
            status: 'collecting',
            currentStep: 'country',
        });

        const persisted = JSON.parse(localStorage.getItem('waa_chat_v1'));
        expect(persisted.activeWorkflow.workflowId).toBe('woocommerce_first_time_setup');
    });

    it('sends active workflow context so follow-up stays inside the workflow until submit', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 222 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'text_delta', content: 'A guided workflow is still in progress. Continue in the form, submit it, or cancel it before switching back to open chat.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        act(() => {
            result.current.startWorkflow('woocommerce_first_time_setup');
        });

        await act(async () => {
            await result.current.sendMessage('Set the currency to VND');
        });

        expect(chatStreamMock).toHaveBeenCalledTimes(1);
        expect(chatStreamMock.mock.calls[0][5]).toMatchObject({
            workflowId: 'woocommerce_first_time_setup',
            status: 'collecting',
        });
        expect(result.current.messages.at(-1).content).toContain('guided workflow');
        expect(result.current.activeWorkflow.status).toBe('collecting');
    });

    it('preserves history chunking, pending navigation, and persisted state after a streamed tool turn', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 42 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 10, output_tokens: 1, cost_usd: 0.01 };
            yield { type: 'text_delta', content: 'Checking plugins.' };
            yield { type: 'tool_start', tool_name: 'list_plugins', tool_use_id: 'tc_1', tool_input: {} };
            yield { type: 'tool_end', tool_name: 'list_plugins', tool_use_id: 'tc_1', result: { plugins: [] } };
            yield { type: 'usage', input_tokens: 20, output_tokens: 5, cost_usd: 0.02 };
            yield { type: 'text_delta', content: ' Done.' };
            yield { type: 'navigate', url: 'http://localhost:8888/wp-admin/plugins.php' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('List installed plugins');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(chatStreamMock).toHaveBeenCalledTimes(1);
        expect(createConversationMock).toHaveBeenCalledTimes(1);
        expect(updateConversationMock).toHaveBeenCalledTimes(1);
        expect(chatStreamMock.mock.calls[0][0]).toBe('List installed plugins');
        expect(chatStreamMock.mock.calls[0][1]).toEqual([]);
        expect(chatStreamMock.mock.calls[0][2]).toBe(42);
        expect(updateConversationMock.mock.calls[0][0]).toBe(42);
        expect(result.current.pendingNavUrl).toBe('http://localhost:8888/wp-admin/plugins.php');
        expect(result.current.messages).toHaveLength(2);
        expect(result.current.messages[1].content).toBe('Checking plugins. Done.');
        expect(result.current.messages[1].toolCalls).toEqual([
            {
                tool_use_id: 'tc_1',
                name: 'list_plugins',
                input: {},
                status: 'done',
                result: { plugins: [] },
            },
        ]);

        const persisted = JSON.parse(localStorage.getItem('waa_chat_v1'));
        expect(persisted.conversationId).toBe(42);
        expect(persisted.history).toEqual([
            { role: 'user', content: 'List installed plugins' },
            {
                role: 'assistant',
                content: 'Checking plugins.',
                tool_calls: [{ id: 'tc_1', name: 'list_plugins', input: {} }],
            },
            {
                role: 'tool',
                tool_call_id: 'tc_1',
                tool_name: 'list_plugins',
                result: { plugins: [] },
            },
            {
                role: 'assistant',
                content: ' Done.',
                tool_calls: [],
            },
        ]);
    });

    it('reuses an existing conversation id for subsequent live chat requests', async () => {
        localStorage.setItem('waa_chat_v1', JSON.stringify({
            messages: [{ role: 'assistant', content: 'Existing message', id: 'saved-1' }],
            history: [{ role: 'assistant', content: 'Existing API history', tool_calls: [] }],
            usage: { input_tokens: 5, output_tokens: 6, cost_usd: 0.02, elapsed_ms: 10 },
            conversationId: 77,
        }));
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 11, output_tokens: 7, cost_usd: 0.03 };
            yield { type: 'text_delta', content: 'Continuing saved conversation.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Continue');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(createConversationMock).not.toHaveBeenCalled();
        expect(chatStreamMock).toHaveBeenCalledTimes(1);
        expect(chatStreamMock.mock.calls[0][2]).toBe(77);
        expect(updateConversationMock).toHaveBeenCalledTimes(1);
        expect(updateConversationMock.mock.calls[0][0]).toBe(77);
    });

    it('preserves a readable error message when the chat request is rate limited', async () => {
        chatStreamMock.mockRejectedValue(new Error('Too many requests. Try again in a minute.'));
        createConversationMock.mockResolvedValue({ id: 314 });
        updateConversationMock.mockResolvedValue({ success: true });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Please continue');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.messages.at(-1)).toMatchObject({
            role: 'assistant',
            content: 'Too many requests. Try again in a minute.',
            isError: true,
        });
        expect(updateConversationMock).toHaveBeenCalledTimes(1);
        expect(updateConversationMock.mock.calls[0][1].messages.at(-1)).toMatchObject({
            content: 'Too many requests. Try again in a minute.',
            isError: true,
        });
    });

    it('marks async tool results as queued and preserves follow-up metadata', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 91 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 4, output_tokens: 1, cost_usd: 0.01 };
            yield { type: 'text_delta', content: 'Starting the scan.' };
            yield { type: 'tool_start', tool_name: 'wordfence_run_scan', tool_use_id: 'tc_scan', tool_input: {} };
            yield {
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
                    message: 'Wordfence scan scheduled. It will run on the next WP-Cron tick (usually within 1 minute). Use wordfence_get_scan_results to check for issues after it completes.',
                },
            };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Run a Wordfence scan');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.messages[1].toolCalls).toEqual([
            {
                tool_use_id: 'tc_scan',
                name: 'wordfence_run_scan',
                input: {},
                status: 'queued',
                result: {
                    success: true,
                    async: true,
                    job_type: 'wordfence_scan',
                    job_status: 'queued',
                    recommended_poll_tool: 'wordfence_get_scan_results',
                    recommended_delay_sec: 60,
                    message: 'Wordfence scan scheduled. It will run on the next WP-Cron tick (usually within 1 minute). Use wordfence_get_scan_results to check for issues after it completes.',
                },
                asyncMeta: {
                    isAsync: true,
                    jobType: 'wordfence_scan',
                    jobStatus: 'queued',
                    followUpTool: 'wordfence_get_scan_results',
                    followUpDelaySec: 60,
                    message: 'Wordfence scan scheduled. It will run on the next WP-Cron tick (usually within 1 minute). Use wordfence_get_scan_results to check for issues after it completes.',
                },
            },
        ]);
    });

    it('holds destructive actions for confirmation and replays them after approval', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 51 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock
            .mockImplementationOnce(async function* () {
                yield { type: 'usage', input_tokens: 8, output_tokens: 2, cost_usd: 0.01 };
                yield {
                    type: 'confirmation_required',
                    tool_name: 'deactivate_plugin',
                    tool_use_id: 'tc_confirm',
                    tool_input: { plugin_file: 'hello-dolly/hello.php' },
                    message: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
                    confirmation: {
                        title: 'Approve change',
                        summary: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
                        impact: 'Turns off active plugin behavior and may change site features immediately.',
                        risk_level: 'destructive',
                        action_type: 'site_write',
                        confirm_label: 'Confirm change',
                        cancel_label: 'Cancel action',
                        is_async: false,
                    },
                };
                yield { type: 'done' };
            })
            .mockImplementationOnce(async function* () {
                yield { type: 'tool_start', tool_name: 'deactivate_plugin', tool_use_id: 'tc_confirm', tool_input: { plugin_file: 'hello-dolly/hello.php' } };
                yield { type: 'tool_end', tool_name: 'deactivate_plugin', tool_use_id: 'tc_confirm', result: { plugin: 'hello-dolly/hello.php' } };
                yield { type: 'text_delta', content: 'Confirmed. The plugin `hello-dolly/hello.php` has been deactivated.' };
                yield { type: 'done' };
            });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Deactivate Hello Dolly');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.pendingConfirmation).toEqual({
            approved: true,
            tool_name: 'deactivate_plugin',
            tool_use_id: 'tc_confirm',
            tool_input: { plugin_file: 'hello-dolly/hello.php' },
            message: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
            title: 'Approve change',
            summary: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
            impact: 'Turns off active plugin behavior and may change site features immediately.',
            riskLevel: 'destructive',
            actionType: 'site_write',
            isAsync: false,
            confirmLabel: 'Confirm change',
            cancelLabel: 'Cancel action',
            currentState: null,
            proposedState: null,
        });
        expect(chatStreamMock.mock.calls[0][4]).toBeNull();

        await act(async () => {
            await result.current.confirmPendingAction();
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(chatStreamMock).toHaveBeenCalledTimes(2);
        expect(chatStreamMock.mock.calls[1][0]).toBe('Yes, proceed.');
        expect(chatStreamMock.mock.calls[1][4]).toEqual({
            approved: true,
            tool_name: 'deactivate_plugin',
            tool_use_id: 'tc_confirm',
            tool_input: { plugin_file: 'hello-dolly/hello.php' },
            message: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
            title: 'Approve change',
            summary: 'This will deactivate plugin `hello-dolly/hello.php`. Confirm to continue.',
            impact: 'Turns off active plugin behavior and may change site features immediately.',
            riskLevel: 'destructive',
            actionType: 'site_write',
            isAsync: false,
            confirmLabel: 'Confirm change',
            cancelLabel: 'Cancel action',
            currentState: null,
            proposedState: null,
        });
        expect(result.current.pendingConfirmation).toBeNull();
        expect(result.current.messages.at(-1).content).toBe('Confirmed. The plugin `hello-dolly/hello.php` has been deactivated.');
    });

    it('preserves prior tool evidence when a later iteration stops for confirmation', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 61 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 8, output_tokens: 2, cost_usd: 0.01 };
            yield { type: 'text_delta', content: 'Checking installed plugins first.' };
            yield { type: 'tool_start', tool_name: 'list_plugins', tool_use_id: 'tc_list', tool_input: {} };
            yield { type: 'tool_end', tool_name: 'list_plugins', tool_use_id: 'tc_list', result: { plugins: [] } };
            yield { type: 'usage', input_tokens: 12, output_tokens: 4, cost_usd: 0.02 };
            yield {
                type: 'confirmation_required',
                tool_name: 'install_plugin',
                tool_use_id: 'tc_install',
                tool_input: { slug: 'woocommerce' },
                message: 'This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.',
                confirmation: {
                    title: 'Approve change',
                    summary: 'This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.',
                    impact: 'Adds new plugin code to this WordPress site, but leaves it inactive until a separate activation step.',
                    risk_level: 'sensitive',
                    action_type: 'extension_install',
                    confirm_label: 'Confirm change',
                    cancel_label: 'Cancel action',
                    is_async: false,
                },
            };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Install WooCommerce');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.pendingConfirmation?.tool_name).toBe('install_plugin');
        expect(result.current.messages.at(-1).content).toBe(
            'Checking installed plugins first.\n\nThis will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.'
        );
        expect(result.current.messages.at(-1).toolCalls).toEqual([
            {
                tool_use_id: 'tc_list',
                name: 'list_plugins',
                input: {},
                status: 'done',
                result: { plugins: [] },
            },
        ]);
        expect(result.current.messages.at(-1).confirmation).toEqual({
            title: 'Approve change',
            summary: 'This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.',
            impact: 'Adds new plugin code to this WordPress site, but leaves it inactive until a separate activation step.',
            toolName: 'install_plugin',
            riskLevel: 'sensitive',
            status: 'pending',
        });
        expect(result.current.apiHistory).toEqual([
            { role: 'user', content: 'Install WooCommerce' },
            {
                role: 'assistant',
                content: 'Checking installed plugins first.',
                tool_calls: [{ id: 'tc_list', name: 'list_plugins', input: {} }],
            },
            {
                role: 'tool',
                tool_call_id: 'tc_list',
                tool_name: 'list_plugins',
                result: { plugins: [] },
            },
            {
                role: 'assistant',
                content: 'This will install plugin `woocommerce`. It will not be activated automatically. Confirm to continue.',
                tool_calls: [],
            },
        ]);
    });

    it('records a local cancellation when the user rejects a pending destructive action', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 88 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 8, output_tokens: 2, cost_usd: 0.01 };
            yield { type: 'confirmation_required', tool_name: 'switch_theme', tool_use_id: 'tc_theme', tool_input: { theme_slug: 'astra' }, message: 'This will switch the active theme to `astra`. Confirm to continue.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Switch to Astra');
        });

        await waitFor(() => {
            expect(result.current.pendingConfirmation?.tool_name).toBe('switch_theme');
        });

        await act(async () => {
            await result.current.cancelPendingAction();
        });

        expect(result.current.pendingConfirmation).toBeNull();
        expect(chatStreamMock).toHaveBeenCalledTimes(1);
        expect(result.current.messages.at(-2).content).toBe('No, cancel that action.');
        expect(result.current.messages.at(-1).content).toBe('Okay, I will not run that action.');
        expect(updateConversationMock).toHaveBeenCalledTimes(2);
    });

    it('adds a failure guard when streamed assistant text claims success after a tool error', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 98 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 6, output_tokens: 2, cost_usd: 0.01 };
            yield { type: 'tool_start', tool_name: 'install_plugin', tool_use_id: 'tc_fail', tool_input: { slug: 'woocommerce' } };
            yield {
                type: 'tool_end',
                tool_name: 'install_plugin',
                tool_use_id: 'tc_fail',
                result: { success: false, error: 'Download failed.' },
            };
            yield { type: 'text_delta', content: 'Confirmed. Plugin installation completed.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Install WooCommerce');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.messages.at(-1).content).toBe(
            'Confirmed. Plugin installation completed.\n\nOne or more tool steps failed. Review the error details above.'
        );
        expect(result.current.messages.at(-1).toolCalls[0].status).toBe('done');
        expect(result.current.messages.at(-1).toolCalls[0].result.error).toBe('Download failed.');
    });

    it('adds a queued guard when streamed assistant text overstates an async result', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 99 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 4, output_tokens: 1, cost_usd: 0.01 };
            yield { type: 'tool_start', tool_name: 'wordfence_run_scan', tool_use_id: 'tc_queue', tool_input: {} };
            yield {
                type: 'tool_end',
                tool_name: 'wordfence_run_scan',
                tool_use_id: 'tc_queue',
                result: {
                    success: true,
                    async: true,
                    job_type: 'wordfence_scan',
                    job_status: 'queued',
                    recommended_poll_tool: 'wordfence_get_scan_results',
                    recommended_delay_sec: 60,
                    message: 'Wordfence scan scheduled.',
                },
            };
            yield { type: 'text_delta', content: 'Done. The scan is complete.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        await act(async () => {
            await result.current.sendMessage('Run a Wordfence scan');
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.messages.at(-1).content).toBe(
            'Done. The scan is complete.\n\nThe requested action has only been queued so far and is still waiting to finish in the background.'
        );
        expect(result.current.messages.at(-1).toolCalls[0].status).toBe('queued');
    });

    it('loads saved api history together with messages and usage', async () => {
        const { result } = await importHook();
        const savedHistory = [
            { role: 'user', content: 'Saved API history' },
            { role: 'assistant', content: 'Saved assistant state', tool_calls: [] },
        ];

        act(() => {
            result.current.loadMessages(
                [{ role: 'user', content: 'Saved message', id: 'saved-1' }],
                { input_tokens: 9, output_tokens: 4, cost_usd: 0.1, elapsed_ms: 20 },
                savedHistory,
                77
            );
        });

        act(() => {
            result.current.clearNavUrl();
        });

        expect(result.current.messages[0].content).toBe('Saved message');
        expect(result.current.apiHistory).toEqual(savedHistory);
        expect(result.current.conversationId).toBe(77);
        expect(result.current.sessionUsage.input_tokens).toBe(9);
    });

    it('persists workflow state and builds a batched apply prompt', async () => {
        chatStreamMock.mockResolvedValue({ body: { mock: true } });
        createConversationMock.mockResolvedValue({ id: 144 });
        updateConversationMock.mockResolvedValue({ success: true });
        parseSSEMock.mockImplementation(async function* () {
            yield { type: 'usage', input_tokens: 5, output_tokens: 2, cost_usd: 0.01 };
            yield { type: 'text_delta', content: 'Applied the WooCommerce setup.' };
            yield { type: 'done' };
        });

        const { result } = await importHook();

        act(() => {
            result.current.startWorkflow('woocommerce_first_time_setup');
            result.current.updateWorkflowAnswer('country', 'VN');
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('city', 'Ho Chi Minh City');
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('currency', 'VND');
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('taxesEnabled', false);
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('couponsEnabled', true);
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('sampleProductEnabled', true);
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('sampleProductName', 'Starter Hoodie');
            result.current.goToNextWorkflowStep();
            result.current.updateWorkflowAnswer('sampleProductPrice', '49.99');
        });

        expect(result.current.activeWorkflow.status).toBe('collecting');

        const persistedBeforeApply = JSON.parse(localStorage.getItem('waa_chat_v1'));
        expect(persistedBeforeApply.activeWorkflow.answers.country).toBe('VN');
        expect(persistedBeforeApply.activeWorkflow.answers.sampleProductName).toBe('Starter Hoodie');

        await act(async () => {
            await result.current.submitWorkflow();
        });

        await waitFor(() => {
            expect(result.current.isLoading).toBe(false);
        });

        expect(result.current.activeWorkflow).toBeNull();
        expect(chatStreamMock).toHaveBeenCalledTimes(1);
        expect(chatStreamMock.mock.calls[0][0]).toContain('Apply this WooCommerce first-time setup now.');
        expect(chatStreamMock.mock.calls[0][0]).toContain('"woocommerce_default_country":"VN"');
        expect(chatStreamMock.mock.calls[0][0]).toContain('"name":"Starter Hoodie"');
        expect(result.current.messages.at(-1).content).toBe('Applied the WooCommerce setup.');
    });
});

async function importHook() {
    const mod = await import('../../../src/hooks/useChat');
    return renderHook(() => mod.useChat());
}
