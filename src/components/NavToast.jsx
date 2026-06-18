import { useState, useEffect } from 'react';
import styles from '../styles/navtoast.module.css';

const COUNTDOWN = 5;

export default function NavToast({ url, onDismiss }) {
    const [seconds, setSeconds] = useState(COUNTDOWN);

    useEffect(() => {
        if (seconds <= 0) {
            window.location.href = url;
            return;
        }
        const t = setTimeout(() => setSeconds(s => s - 1), 1000);
        return () => clearTimeout(t);
    }, [seconds, url]);

    const pageName = url.split('/').pop().replace('.php', '').replace('-', ' ');

    return (
        <div className={styles.toast}>
            <span className={styles.msg}>
                Navigating to <strong>{pageName}</strong> in {seconds}s…
            </span>
            <div className={styles.actions}>
                <button className={styles.goBtn} onClick={() => { window.location.href = url; }}>
                    Go now
                </button>
                <button className={styles.cancelBtn} onClick={onDismiss}>
                    Cancel
                </button>
            </div>
        </div>
    );
}
