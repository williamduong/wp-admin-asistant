import { useState, useRef } from 'react';
import styles from '../styles/input.module.css';

export default function InputBar({ onSend, isLoading }) {
    const [text,    setText]    = useState('');
    const textareaRef           = useRef(null);

    const handleSend = () => {
        const trimmed = text.trim();
        if (!trimmed || isLoading) return;
        onSend(trimmed);
        setText('');
        textareaRef.current?.focus();
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSend();
        }
    };

    return (
        <div className={styles.wrap}>
            <div className={styles.bar}>
                <textarea
                    ref={textareaRef}
                    className={styles.textarea}
                    value={text}
                    onChange={e => setText(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder="Ask anything about your WordPress site…"
                    rows={2}
                    disabled={isLoading}
                    aria-label="Message input"
                />
                <button
                    className={styles.sendBtn}
                    onClick={handleSend}
                    disabled={!text.trim() || isLoading}
                    aria-label="Send message"
                >
                    {isLoading ? '…' : '↑'}
                </button>
            </div>
        </div>
    );
}
