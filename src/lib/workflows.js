export const WORKFLOW_DEFINITIONS = {
    woocommerce_first_time_setup: {
        id: 'woocommerce_first_time_setup',
        title: 'WooCommerce first-time setup',
        launchCommands: ['/woo-setup', '/wizard woocommerce'],
        steps: [
            { key: 'country', label: 'Store country', placeholder: 'Example: US:CA or VN', required: true },
            { key: 'city', label: 'Store city', placeholder: 'Example: Ho Chi Minh City', required: true },
            { key: 'currency', label: 'Currency', placeholder: 'Example: USD, VND, EUR', required: true },
            { key: 'taxesEnabled', label: 'Enable taxes?', type: 'boolean', required: true },
            { key: 'couponsEnabled', label: 'Enable coupons?', type: 'boolean', required: true },
            { key: 'sampleProductEnabled', label: 'Create a sample product?', type: 'boolean', required: true },
            { key: 'sampleProductName', label: 'Sample product name', placeholder: 'Example: Starter Hoodie', required: true, dependsOn: 'sampleProductEnabled' },
            { key: 'sampleProductPrice', label: 'Sample product price', placeholder: 'Example: 49.99', required: true, dependsOn: 'sampleProductEnabled' },
        ],
        createInitialAnswers() {
            return {
                country: '',
                city: '',
                currency: '',
                taxesEnabled: null,
                couponsEnabled: null,
                sampleProductEnabled: null,
                sampleProductName: '',
                sampleProductPrice: '',
            };
        },
        buildApplyPrompt(answers) {
            const updates = {
                woocommerce_default_country: answers.country,
                woocommerce_store_city: answers.city,
                woocommerce_currency: answers.currency,
                woocommerce_calc_taxes: answers.taxesEnabled ? 'yes' : 'no',
                woocommerce_enable_coupons: answers.couponsEnabled ? 'yes' : 'no',
            };

            const lines = [
                'Apply this WooCommerce first-time setup now.',
                'You are operating inside the predefined workflow `woocommerce_first_time_setup`.',
                'Use the dedicated WooCommerce tools only.',
                `1. Call update_woocommerce_settings with exactly this updates object: ${JSON.stringify(updates)}.`,
            ];

            if (answers.sampleProductEnabled) {
                lines.push(`2. After the settings update succeeds, call create_woocommerce_product with exactly this payload: ${JSON.stringify({
                    name: answers.sampleProductName,
                    status: 'draft',
                    regular_price: answers.sampleProductPrice,
                    stock_status: 'instock',
                })}.`);
            }

            lines.push('Do not ask follow-up questions.');
            lines.push('Summarize the applied changes briefly after the tools finish.');

            return lines.join('\n');
        },
    },
};

export function getWorkflowDefinition(workflowId) {
    return WORKFLOW_DEFINITIONS[workflowId] ?? null;
}

export function createWorkflowState(workflowId) {
    const definition = getWorkflowDefinition(workflowId);
    if (!definition) {
        return null;
    }

    return {
        kind: 'wizard',
        workflowId,
        status: 'collecting',
        currentStep: definition.steps[0]?.key ?? null,
        answers: definition.createInitialAnswers(),
    };
}

export function getVisibleWorkflowSteps(definition, answers) {
    return definition.steps.filter((step) => !step.dependsOn || Boolean(answers[step.dependsOn]));
}

export function getWorkflowStepIndex(definition, stepKey) {
    return definition.steps.findIndex((step) => step.key === stepKey);
}

export function getWorkflowCurrentStep(definition, workflow) {
    return definition.steps.find((step) => step.key === workflow?.currentStep) ?? definition.steps[0] ?? null;
}

export function getNextWorkflowStep(definition, workflow) {
    const visible = getVisibleWorkflowSteps(definition, workflow.answers);
    const currentIndex = visible.findIndex((step) => step.key === workflow.currentStep);
    return visible[currentIndex + 1] ?? null;
}

export function getPreviousWorkflowStep(definition, workflow) {
    const visible = getVisibleWorkflowSteps(definition, workflow.answers);
    const currentIndex = visible.findIndex((step) => step.key === workflow.currentStep);
    return visible[currentIndex - 1] ?? null;
}

export function isWorkflowStepComplete(step, answers) {
    if (step.dependsOn && !answers[step.dependsOn]) {
        return true;
    }

    const value = answers[step.key];

    if (step.type === 'boolean') {
        return typeof value === 'boolean';
    }

    return String(value ?? '').trim() !== '';
}

export function isWorkflowReady(definition, workflow) {
    return getVisibleWorkflowSteps(definition, workflow.answers).every((step) => isWorkflowStepComplete(step, workflow.answers));
}

export function matchWorkflowLaunchCommand(text) {
    const normalized = String(text ?? '').trim().toLowerCase();

    for (const definition of Object.values(WORKFLOW_DEFINITIONS)) {
        if (definition.launchCommands.includes(normalized)) {
            return definition.id;
        }
    }

    return null;
}
