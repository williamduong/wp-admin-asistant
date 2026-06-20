import { useState } from 'react';
import styles from '../styles/messages.module.css';

const debugMode = window.waaData?.debugMode ?? 'off';

function formatElapsed(ms) {
    if (!ms || ms <= 0) return null;
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
}

function ToolCallRow({ tc }) {
    const [expanded, setExpanded] = useState(false);
    const hasError = tc.result && (tc.result.success === false || tc.result.error);
    const isPending = tc.status === 'needs_confirmation';
    const isQueued = tc.status === 'queued';
    const asyncHint = tc.asyncMeta?.message ?? '';
    const duration = formatElapsed(tc.duration_ms);

    return (
        <div className={styles.toolRow}>
            <span
                className={`${styles.toolBadge} ${isPending ? styles.toolPending : isQueued ? styles.toolQueued : tc.status === 'done' ? (hasError ? styles.toolError : styles.toolDone) : styles.toolRunning}`}
                onClick={() => debugMode !== 'off' && tc.status === 'done' && setExpanded(e => !e)}
                style={debugMode !== 'off' && tc.status === 'done' ? { cursor: 'pointer' } : {}}
                title={debugMode !== 'off' ? 'Click to expand' : undefined}
            >
                {isPending ? '!' : isQueued ? '…' : tc.status === 'running' ? '⚙' : (hasError ? '✗' : '✓')} {tc.name}
                {debugMode === 'full' && duration && <span className={styles.toolDuration}>{duration}</span>}
                {debugMode !== 'off' && tc.status === 'done' && <span className={styles.expandToggle}>{expanded ? ' ▲' : ' ▼'}</span>}
            </span>

            {isQueued && asyncHint && (
                <span className={styles.asyncHint}>{asyncHint}</span>
            )}

            {/* compact: show error summary inline */}
            {debugMode === 'compact' && tc.status === 'done' && hasError && !expanded && (
                <span className={styles.errorHint}>
                    {tc.result?.error ?? tc.result?.message ?? 'failed'}
                </span>
            )}

            {/* full or compact (expanded): show full JSON */}
            {(debugMode === 'full' || expanded) && tc.status === 'done' && (
                <div className={styles.toolDetail}>
                    {debugMode === 'full' && Object.keys(tc.input ?? {}).length > 0 && (
                        <div>
                            <div className={styles.detailLabel}>Input</div>
                            <pre className={styles.detailPre}>{JSON.stringify(tc.input, null, 2)}</pre>
                        </div>
                    )}
                    <div>
                        <div className={styles.detailLabel}>Result</div>
                        <pre className={styles.detailPre}>{JSON.stringify(tc.result, null, 2)}</pre>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function Message({ message }) {
    const isUser     = message.role === 'user';
    const isTool     = message.role === 'tool';
    const hasTools   = message.toolCalls?.length > 0;
    const hasContent = message.content && message.content.trim().length > 0;
    const confirmationSummary = message.confirmation?.summary ?? message.confirmation?.message ?? '';
    const hasConfirmation = Boolean(confirmationSummary);
    const showsDuplicateConfirmation = hasContent && message.content.trim() === confirmationSummary.trim();

    return (
        <div className={`${styles.message} ${isUser ? styles.user : isTool ? styles.tool : styles.assistant} ${message.isError ? styles.error : ''}`}>
            {/* Tool call badges */}
            {hasTools && (
                <div className={styles.toolCalls}>
                    {message.toolCalls.map((tc, i) => (
                        <ToolCallRow key={i} tc={tc} />
                    ))}
                </div>
            )}

            {/* Tool result messages (standalone tool role) */}
            {isTool && (
                <div className={styles.toolResultMessage}>
                    <div className={styles.toolResultLabel}>Tool result</div>
                    <pre className={styles.toolResultPre}>{JSON.stringify(message.result ?? message, null, 2)}</pre>
                </div>
            )}

            {/* Message text — only render if non-empty */}
            {hasContent && (
                <div className={styles.content} style={{ whiteSpace: 'pre-wrap' }}>
                    {message.content}
                </div>
            )}

            {hasConfirmation && !showsDuplicateConfirmation && (
                <div className={styles.confirmationHint}>
                    <span className={styles.confirmationLabel}>Pending confirmation</span>
                    {confirmationSummary}
                </div>
            )}

            {/* Fallback: nothing to show at all */}
            {!hasContent && !hasTools && !isTool && !hasConfirmation && !message.isError && (
                <div className={`${styles.content} ${styles.empty}`}>…</div>
            )}
        </div>
    );
}
