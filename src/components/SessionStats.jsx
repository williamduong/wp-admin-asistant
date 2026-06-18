import styles from '../styles/stats.module.css';

const { provider, model, pricing } = window.waaData ?? {};

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

export default function SessionStats({ usage }) {
    const { input_tokens, output_tokens, cost_usd, elapsed_ms } = usage;
    const total   = input_tokens + output_tokens;
    const info    = getPricing();
    const isFree  = info ? (info.in === 0 && info.out === 0) : false;
    const elapsed = formatElapsed(elapsed_ms);

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
        </div>
    );
}
