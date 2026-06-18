import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import MultiQuestionForm from '../../../src/components/MultiQuestionForm';
import { getWorkflowDefinition } from '../../../src/lib/workflows';

describe('MultiQuestionForm', () => {
    it('renders tabbed workflow navigation and submit action', () => {
        const onSubmit = vi.fn();
        const onSelectStep = vi.fn();
        const definition = getWorkflowDefinition('woocommerce_first_time_setup');

        render(
            <MultiQuestionForm
                definition={definition}
                workflow={{
                    kind: 'wizard',
                    workflowId: 'woocommerce_first_time_setup',
                    currentStep: 'country',
                    status: 'collecting',
                    answers: {
                        country: 'VN',
                        city: 'Ho Chi Minh City',
                        currency: 'VND',
                        taxesEnabled: false,
                        couponsEnabled: true,
                        sampleProductEnabled: true,
                        sampleProductName: 'Starter Hoodie',
                        sampleProductPrice: '49.99',
                    },
                }}
                isLoading={false}
                onUpdateAnswer={vi.fn()}
                onNext={vi.fn()}
                onBack={vi.fn()}
                onSelectStep={onSelectStep}
                onCancel={vi.fn()}
                onSubmit={onSubmit}
            />
        );

        expect(screen.getByText('WooCommerce first-time setup')).toBeInTheDocument();
        expect(screen.getAllByText('Store country').length).toBeGreaterThan(0);
        expect(screen.getAllByText('VN').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Starter Hoodie').length).toBeGreaterThan(0);
        expect(screen.getByRole('tablist', { name: 'Workflow questions' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('tab', { name: /Currency/i }));
        expect(onSelectStep).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByRole('button', { name: 'Submit setup' }));

        expect(onSubmit).toHaveBeenCalledTimes(1);
    });
});
