import { useMemo } from 'react';
import styles from '../styles/wizard.module.css';
import { getVisibleWorkflowSteps, isWorkflowReady, isWorkflowStepComplete } from '../lib/workflows';

function humanizeAnswer(step, value) {
    if (step?.type === 'boolean') {
        if (value === true) return 'Yes';
        if (value === false) return 'No';
        return 'Not answered';
    }

    return value == null || value === '' ? 'Not answered' : String(value);
}

export default function MultiQuestionForm({
    definition,
    workflow,
    isLoading,
    onUpdateAnswer,
    onNext,
    onBack,
    onSelectStep,
    onCancel,
    onSubmit,
}) {
    if (!definition || !workflow) {
        return null;
    }

    const visibleSteps = getVisibleWorkflowSteps(definition, workflow.answers);
    const activeStep = definition.steps.find((step) => step.key === workflow.currentStep) ?? visibleSteps[0] ?? null;
    const activeVisibleIndex = visibleSteps.findIndex((step) => step.key === activeStep?.key);
    const canSubmit = isWorkflowReady(definition, workflow);

    const summaryRows = useMemo(() => (
        visibleSteps.map((step) => ({
            key: step.key,
            label: step.label,
            value: humanizeAnswer(step, workflow.answers[step.key]),
            complete: isWorkflowStepComplete(step, workflow.answers),
        }))
    ), [visibleSteps, workflow.answers]);

    return (
        <div className={styles.overlay}>
            <div className={styles.modal} role="dialog" aria-modal="true" aria-label={definition.title}>
                <div className={styles.headerRow}>
                    <div>
                        <div className={styles.eyebrow}>Multi-question form</div>
                        <div className={styles.title}>{definition.title}</div>
                    </div>
                    <button type="button" className={styles.dismissBtn} onClick={onCancel}>
                        Cancel
                    </button>
                </div>

                <div className={styles.body}>
                    <div className={styles.tabColumn}>
                        <div className={styles.tabLabel}>Questions</div>
                        <div className={styles.tabList} role="tablist" aria-label="Workflow questions">
                            {visibleSteps.map((step, index) => {
                                const selected = step.key === activeStep?.key;
                                const complete = isWorkflowStepComplete(step, workflow.answers);

                                return (
                                    <button
                                        key={step.key}
                                        type="button"
                                        role="tab"
                                        aria-selected={selected}
                                        className={`${styles.tabBtn} ${selected ? styles.tabBtnActive : ''}`}
                                        onClick={() => onSelectStep(step.key)}
                                    >
                                        <span className={styles.tabIndex}>{index + 1}</span>
                                        <span className={styles.tabText}>
                                            <span>{step.label}</span>
                                            <span className={styles.tabStatus}>{complete ? 'Done' : 'Open'}</span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    <div className={styles.panelColumn}>
                        <div className={styles.progress}>
                            Question {Math.max(activeVisibleIndex + 1, 1)} of {visibleSteps.length}
                        </div>
                        <div className={styles.question}>{activeStep?.label}</div>
                        <p className={styles.summary}>
                            Complete the form, move between questions freely, then submit once so the workflow can apply everything in one batch.
                        </p>

                        {activeStep?.type === 'boolean' ? (
                            <div className={styles.booleanGroup}>
                                <button
                                    type="button"
                                    className={`${styles.choiceBtn} ${workflow.answers[activeStep.key] === true ? styles.choiceBtnActive : ''}`}
                                    onClick={() => onUpdateAnswer(activeStep.key, true)}
                                >
                                    Yes
                                </button>
                                <button
                                    type="button"
                                    className={`${styles.choiceBtn} ${workflow.answers[activeStep.key] === false ? styles.choiceBtnActive : ''}`}
                                    onClick={() => onUpdateAnswer(activeStep.key, false)}
                                >
                                    No
                                </button>
                            </div>
                        ) : (
                            <input
                                className={styles.textInput}
                                type="text"
                                value={workflow.answers[activeStep?.key] ?? ''}
                                onChange={(event) => onUpdateAnswer(activeStep.key, event.target.value)}
                                placeholder={activeStep?.placeholder || ''}
                                disabled={isLoading}
                            />
                        )}

                        <div className={styles.footerActions}>
                            <button type="button" className={styles.secondaryBtn} onClick={onBack} disabled={activeVisibleIndex <= 0 || isLoading}>
                                Back
                            </button>
                            <button type="button" className={styles.secondaryBtn} onClick={onNext} disabled={activeVisibleIndex >= visibleSteps.length - 1 || isLoading}>
                                Next
                            </button>
                        </div>
                    </div>

                    <div className={styles.reviewColumn}>
                        <div className={styles.tabLabel}>Answers</div>
                        <div className={styles.reviewList}>
                            {summaryRows.map((row) => (
                                <div key={row.key} className={styles.reviewRow}>
                                    <span className={styles.reviewLabel}>{row.label}</span>
                                    <span className={styles.reviewValue}>{row.value}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                </div>

                <div className={styles.modalFooter}>
                    <div className={styles.footerHint}>
                        This form collects inputs once, then the wizard engine applies them in a controlled workflow.
                    </div>
                    <div className={styles.footerActionGroup}>
                        <button type="button" className={styles.secondaryBtn} onClick={onCancel} disabled={isLoading}>
                            Cancel
                        </button>
                        <button type="button" className={styles.primaryBtn} onClick={onSubmit} disabled={!canSubmit || isLoading}>
                            Submit setup
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
