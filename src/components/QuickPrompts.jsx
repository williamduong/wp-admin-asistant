import { QUICK_PROMPTS } from '../lib/quickPrompts';
import styles             from '../styles/widget.module.css';

export default function QuickPrompts({ onSelect }) {
    return (
        <div className={styles.quickPrompts}>
            <p className={styles.quickLabel}>Try asking:</p>
            {QUICK_PROMPTS.map((p, i) => (
                <button key={i} className={styles.chip} onClick={() => onSelect(p.text)}>
                    {p.label}
                </button>
            ))}
        </div>
    );
}
