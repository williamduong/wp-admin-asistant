import styles from '../styles/stats.module.css';

const { provider, model, pricing } = window.waaData ?? {};
const debugMode = window.waaData?.debugMode ?? 'off';

function getPricing() {
    return pricing?.[provider]?.[model] ?? null;
}

function formatCost(usd) {
    if (usd === 0) return 'Free';
    if (usd < 0.000001) return '< $0.000001';
    if (usd < 0.01)     return `$${usd.toFixed(6)}`;
    return `$${usd.toFixed(4)}`;
}

function formatTokens(n) {
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(2) + 'M';
    if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'K';
    return String(n);
}

function formatElapsed(ms) {
    if (!ms || ms === 0) return null;
    if (ms < 1000) return `${ms}ms`;
    return `${(ms / 1000).toFixed(1)}s`;
}

function buildTraceItems(trace) {
    if (!trace) {
        return [];
    }

    const llmTotal = (trace.llm_rounds ?? []).reduce((sum, round) => sum + (round.duration_ms ?? 0), 0);
    const toolTotal = (trace.tools ?? []).reduce((sum, tool) => sum + (tool.duration_ms ?? 0), 0);

    return [
        trace.first_event_ms ? `first byte ${formatElapsed(trace.first_event_ms)}` : null,
        trace.first_text_ms ? `first text ${formatElapsed(trace.first_text_ms)}` : null,
        llmTotal ? `llm ${formatElapsed(llmTotal)}` : null,
        toolTotal ? `tools ${formatElapsed(toolTotal)}` : null,
        trace.create_conversation_ms ? `create ${formatElapsed(trace.create_conversation_ms)}` : null,
        trace.update_conversation_ms ? `save ${formatElapsed(trace.update_conversation_ms)}` : null,
    ].filter(Boolean);
}

export default function SessionStats({ usage }) {
    const { input_tokens, output_tokens, cost_usd, elapsed_ms, trace } = usage;
    const total   = input_tokens + output_tokens;
    const info    = getPricing();
    const isFree  = info ? (info.in === 0 && info.out === 0) : false;
    const elapsed = formatElapsed(elapsed_ms);
    const traceItems = buildTraceItems(trace);

    if (total === 0) return null;

    return (
        <div className={styles.bar}>
            <span className={styles.item} title="Input tokens sent to AI">
                ↑ {formatTokens(input_tokens)}
            </span>
            <span className={styles.sep}>·</span>
            <span className={styles.item} title="Output tokens received from AI">
                ↓ {formatTokens(output_tokens)}
            </span>
            <span className={styles.sep}>·</span>
            <span className={`${styles.item} ${styles.cost}`} title={`Input: $${info?.in ?? 0}/M · Output: $${info?.out ?? 0}/M`}>
                {isFree ? '🆓 Local' : `≈ ${formatCost(cost_usd)}`}
            </span>
            {elapsed && (
                <>
                    <span className={styles.sep}>·</span>
                    <span className={styles.item} title="Total response time">
                        ⏱ {elapsed}
                    </span>
                </>
            )}
            {info && !isFree && (
                <span className={styles.model} title="Active model">
                    {model}
                </span>
            )}
            {debugMode === 'full' && traceItems.length > 0 && (
                <div className={styles.traceRow} title="Request timing breakdown">
                    {traceItems.map((item) => (
                        <span key={item} className={styles.traceItem}>{item}</span>
                    ))}
                </div>
            )}
        </div>
    );
}
