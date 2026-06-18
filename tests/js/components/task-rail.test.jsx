import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import TaskRail from '../../../src/components/TaskRail';

describe('TaskRail', () => {
    it('renders pending confirmation details and actions', () => {
        const onConfirm = vi.fn();
        const onCancel = vi.fn();

        render(
            <TaskRail
                pendingConfirmation={{
                    title: 'Approve change',
                    summary: 'This will publish post `Launch update`.',
                    impact: 'The post will become visible on the live site.',
                    riskLevel: 'destructive',
                    currentState: { status: 'draft' },
                    proposedState: { status: 'publish', visibility: 'public' },
                    confirmLabel: 'Publish now',
                    cancelLabel: 'Keep draft',
                }}
                activeToolName={null}
                isLoading={false}
                queuedTask={null}
                onConfirmPendingAction={onConfirm}
                onCancelPendingAction={onCancel}
            />
        );

        expect(screen.getByText('Awaiting approval')).toBeInTheDocument();
        expect(screen.getByText('This will publish post `Launch update`.')).toBeInTheDocument();
        expect(screen.getByText('The post will become visible on the live site.')).toBeInTheDocument();
        expect(screen.getByText('status: draft')).toBeInTheDocument();
        expect(screen.getByText('status: publish • visibility: public')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Publish now' }));
        fireEvent.click(screen.getByRole('button', { name: 'Keep draft' }));

        expect(onConfirm).toHaveBeenCalledTimes(1);
        expect(onCancel).toHaveBeenCalledTimes(1);
    });

    it('renders a working state when a tool is active', () => {
        render(
            <TaskRail
                pendingConfirmation={null}
                activeToolName="update_site_settings"
                isLoading
                queuedTask={null}
                onConfirmPendingAction={vi.fn()}
                onCancelPendingAction={vi.fn()}
            />
        );

        expect(screen.getByText('Working')).toBeInTheDocument();
        expect(screen.getByText('Update Site Settings')).toBeInTheDocument();
        expect(screen.getByText('The assistant is running this step now.')).toBeInTheDocument();
    });

    it('renders queued async follow-up guidance', () => {
        render(
            <TaskRail
                pendingConfirmation={null}
                activeToolName={null}
                isLoading={false}
                queuedTask={{
                    name: 'wordfence_run_scan',
                    asyncMeta: {
                        message: 'Wordfence scan scheduled and waiting for WP-Cron.',
                        followUpTool: 'wordfence_get_scan_results',
                        followUpDelaySec: 60,
                    },
                }}
                onConfirmPendingAction={vi.fn()}
                onCancelPendingAction={vi.fn()}
            />
        );

        expect(screen.getByText('Background task')).toBeInTheDocument();
        expect(screen.getByText('Wordfence Run Scan')).toBeInTheDocument();
        expect(screen.getByText('Wordfence scan scheduled and waiting for WP-Cron.')).toBeInTheDocument();
        expect(screen.getByText('Follow-up: wordfence_get_scan_results in about 60 seconds.')).toBeInTheDocument();
    });

    it('renders object state values without crashing', () => {
        render(
            <TaskRail
                pendingConfirmation={{
                    title: 'Approve change',
                    summary: 'This will install plugin `woocommerce`.',
                    impact: 'Adds code to the site.',
                    riskLevel: 'sensitive',
                    currentState: null,
                    proposedState: { plugin_slug: 'woocommerce' },
                    confirmLabel: 'Install',
                    cancelLabel: 'Cancel',
                }}
                activeToolName={null}
                isLoading={false}
                queuedTask={null}
                onConfirmPendingAction={vi.fn()}
                onCancelPendingAction={vi.fn()}
            />
        );

        expect(screen.getByText('plugin_slug: woocommerce')).toBeInTheDocument();
    });
});
