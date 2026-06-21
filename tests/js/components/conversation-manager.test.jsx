import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ConversationManager from '../../../src/components/ConversationManager';

const listConversationsMock = vi.fn();
const updateConversationMock = vi.fn();
const loadConversationMock = vi.fn();
const archiveConversationMock = vi.fn();

vi.mock('../../../src/lib/api', () => ({
    listConversations: (...args) => listConversationsMock(...args),
    updateConversation: (...args) => updateConversationMock(...args),
    loadConversation: (...args) => loadConversationMock(...args),
    archiveConversation: (...args) => archiveConversationMock(...args),
}));

describe('ConversationManager', () => {
    const sessions = [
        { id: 11, title: 'Install WooCommerce', updated_at: '2026-06-08T13:25:00.000Z', meta: { archived: false } },
        { id: 12, title: 'Switch to Astra', updated_at: '2026-06-07T13:25:00.000Z', meta: { archived: false } },
        { id: 13, title: 'Deactivate Hello Dolly', updated_at: '2026-06-06T13:25:00.000Z', meta: { archived: false } },
    ];

    beforeEach(() => {
        listConversationsMock.mockReset();
        updateConversationMock.mockReset();
        loadConversationMock.mockReset();
        archiveConversationMock.mockReset();
        vi.stubGlobal('confirm', vi.fn(() => true));
    });

    afterEach(() => {
        cleanup();
        vi.unstubAllGlobals();
    });

    it('excludes the current session from history and filters by search', async () => {
        listConversationsMock.mockResolvedValue(sessions);

        render(
            <ConversationManager
                currentConversationId={11}
                onLoad={vi.fn()}
                onClose={vi.fn()}
            />
        );

        expect(await screen.findByText('Session History')).toBeInTheDocument();
        await waitFor(() => {
            expect(screen.queryByText('Install WooCommerce')).not.toBeInTheDocument();
        });
        expect(screen.getByText('Switch to Astra')).toBeInTheDocument();
        expect(screen.getByText('Deactivate Hello Dolly')).toBeInTheDocument();
        expect(screen.getByText(/Session #12/)).toBeInTheDocument();
        expect(screen.getByText(/Session #13/)).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Search sessions'), {
            target: { value: 'astra' },
        });

        expect(screen.getByText('Switch to Astra')).toBeInTheDocument();
        expect(screen.queryByText('Deactivate Hello Dolly')).not.toBeInTheDocument();
    });

    it('renames a session inline and updates the visible title', async () => {
        listConversationsMock.mockResolvedValue(sessions);
        updateConversationMock.mockResolvedValue({ success: true });

        render(
            <ConversationManager
                currentConversationId={null}
                onLoad={vi.fn()}
                onClose={vi.fn()}
            />
        );

        await screen.findByText('Install WooCommerce');

        fireEvent.click(screen.getAllByText('Rename')[0]);
        fireEvent.change(screen.getByLabelText('Session name'), {
            target: { value: 'WooCommerce setup' },
        });
        fireEvent.submit(screen.getByText('Save').closest('form'));

        await waitFor(() => {
            expect(updateConversationMock).toHaveBeenCalledWith(11, { title: 'WooCommerce setup' });
        });

        expect(await screen.findByText('WooCommerce setup')).toBeInTheDocument();
    });

    it('archives a session and removes it from the current history list', async () => {
        listConversationsMock.mockResolvedValue(sessions);
        archiveConversationMock.mockResolvedValue({ success: true });

        render(
            <ConversationManager
                currentConversationId={null}
                onLoad={vi.fn()}
                onClose={vi.fn()}
            />
        );

        await screen.findByText('Install WooCommerce');

        fireEvent.click(screen.getAllByText('Archive')[0]);

        await waitFor(() => {
            expect(archiveConversationMock).toHaveBeenCalledWith(11);
        });

        expect(screen.queryByText('Install WooCommerce')).not.toBeInTheDocument();
    });

    it('loads a selected session and closes the manager', async () => {
        listConversationsMock.mockResolvedValue(sessions);
        loadConversationMock.mockResolvedValue({
            id: 12,
            messages: [{ role: 'user', content: 'Switch to Astra' }],
            history: [{ role: 'user', content: 'Switch to Astra' }],
            usage: { input_tokens: 1, output_tokens: 1, cost_usd: 0.01, elapsed_ms: 5 },
            meta: { active_workflow: null },
        });

        const onLoad = vi.fn();
        const onClose = vi.fn();

        render(
            <ConversationManager
                currentConversationId={null}
                onLoad={onLoad}
                onClose={onClose}
            />
        );

        await screen.findByText('Switch to Astra');
        fireEvent.click(screen.getByText('Switch to Astra'));

        await waitFor(() => {
            expect(loadConversationMock).toHaveBeenCalledWith(12);
        });

        expect(onLoad).toHaveBeenCalledWith(
            [{ role: 'user', content: 'Switch to Astra' }],
            { input_tokens: 1, output_tokens: 1, cost_usd: 0.01, elapsed_ms: 5 },
            [{ role: 'user', content: 'Switch to Astra' }],
            12,
            { active_workflow: null }
        );
        expect(onClose).toHaveBeenCalled();
    });
});
