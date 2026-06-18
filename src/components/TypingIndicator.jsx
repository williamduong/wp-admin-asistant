import styles from '../styles/messages.module.css';

export default function TypingIndicator({ toolName }) {
    return (
        <div className={styles.typing}>
            {toolName ? (
                <span>⚙️ Calling <code>{toolName}</code>…</span>
            ) : (
                <span className={styles.dots}>
                    <span>.</span><span>.</span><span>.</span>
                </span>
            )}
        </div>
    );
}
