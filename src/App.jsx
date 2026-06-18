import { useState } from 'react';
import ChatWidget from './components/ChatWidget';

const OPEN_KEY = 'waa_widget_open';

function readOpen() {
    try { return localStorage.getItem(OPEN_KEY) === '1'; } catch { return false; }
}

export default function App() {
    const [isOpen, setIsOpen] = useState(readOpen);

    function handleToggle() {
        setIsOpen(o => {
            const next = !o;
            try { localStorage.setItem(OPEN_KEY, next ? '1' : '0'); } catch {}
            return next;
        });
    }

    return (
        <ChatWidget
            isOpen={isOpen}
            onToggle={handleToggle}
        />
    );
}
