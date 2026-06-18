import styles from '../styles/task-rail.module.css';

function humanizeToolName(toolName) {
    return (toolName ?? '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function formatStateValue(value) {
    if (value == null || value === '') {
        return null;
    }

    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return String(value);
    }

    if (Array.isArray(value)) {
        return value.map((entry) => formatStateValue(entry)).filter(Boolean).join(', ');
    }

    if (typeof value === 'object') {
        return Object.entries(value)
            .map(([key, entry]) => {
                const formatted = formatStateValue(entry);
                return formatted ? `${key}: ${formatted}` : key;
            })
            .join(' • ');
    }

    return String(value);
}

function StatePair({ label, value }) {
    const formatted = formatStateValue(value);

    if (!formatted) {
        return null;
    }

    return (
        <div className={styles.stateRow}>
            <span className={styles.stateLabel}>{label}</span>
            <span className={styles.stateValue}>{formatted}</span>
        </div>
    );
}

export default function TaskRail({
    pendingConfirmation,
    activeToolName,
    isLoading,
    queuedTask,
    onConfirmPendingAction,
    onCancelPendingAction,
}) {
    if (pendingConfirmation) {
        return (
            <div className={`${styles.rail} ${styles.warning}`} role="status" aria-live="polite">
                <div className={styles.headerRow}>
                    <div>
                        <div className={styles.eyebrow}>Awaiting approval</div>
                        <div className={styles.title}>{pendingConfirmation.title || 'Approve change'}</div>
                    </div>
                    <span className={styles.badge}>{pendingConfirmation.riskLevel || 'sensitive'}</span>
                </div>

                <div className={styles.summary}>
                    {pendingConfirmation.summary || pendingConfirmation.message || 'Please confirm this action before it runs.'}
                </div>

                {pendingConfirmation.impact && (
                    <div className={styles.subtext}>{pendingConfirmation.impact}</div>
                )}

                {(pendingConfirmation.currentState || pendingConfirmation.proposedState) && (
                    <div className={styles.stateGrid}>
                        <StatePair label="Current" value={pendingConfirmation.currentState} />
                        <StatePair label="Next" value={pendingConfirmation.proposedState} />
                    </div>
                )}

                <div className={styles.subtext}>Nothing will change until you confirm.</div>

                <div className={styles.actions}>
                    <button
                        className={styles.primaryBtn}
                        onClick={onConfirmPendingAction}
                        disabled={isLoading}
                        type="button"
                    >
                        {pendingConfirmation.confirmLabel || 'Confirm change'}
                    </button>
                    <button
                        className={styles.secondaryBtn}
                        onClick={onCancelPendingAction}
                        disabled={isLoading}
                        type="button"
                    >
                        {pendingConfirmation.cancelLabel || 'Cancel action'}
                    </button>
                </div>
            </div>
        );
    }

    if (isLoading && activeToolName) {
        return (
            <div className={`${styles.rail} ${styles.neutral}`} role="status" aria-live="polite">
                <div className={styles.eyebrow}>Working</div>
                <div className={styles.title}>{humanizeToolName(activeToolName)}</div>
                <div className={styles.subtext}>The assistant is running this step now.</div>
            </div>
        );
    }

    if (queuedTask) {
        return (
            <div className={`${styles.rail} ${styles.info}`} role="status" aria-live="polite">
                <div className={styles.headerRow}>
                    <div>
                        <div className={styles.eyebrow}>Background task</div>
                        <div className={styles.title}>{humanizeToolName(queuedTask.name)}</div>
                    </div>
                    <span className={styles.badge}>queued</span>
                </div>
                <div className={styles.summary}>
                    {queuedTask.asyncMeta?.message || 'This action was queued to finish in the background.'}
                </div>
                {(queuedTask.asyncMeta?.followUpTool || queuedTask.asyncMeta?.followUpDelaySec) && (
                    <div className={styles.subtext}>
                        {queuedTask.asyncMeta?.followUpTool ? `Follow-up: ${queuedTask.asyncMeta.followUpTool}` : 'Follow-up available'}
                        {queuedTask.asyncMeta?.followUpDelaySec ? ` in about ${queuedTask.asyncMeta.followUpDelaySec} seconds.` : '.'}
                    </div>
                )}
            </div>
        );
    }

    return null;
}
