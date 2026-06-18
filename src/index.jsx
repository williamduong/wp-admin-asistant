import { createRoot } from 'react-dom/client';
import App from './App';

const root = document.getElementById('waa-root');
if (root) {
    createRoot(root).render(<App />);
}
