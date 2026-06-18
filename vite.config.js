import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
    plugins: [react()],

    build: {
        outDir:   'assets',
        emptyOutDir: true,
        rollupOptions: {
            input: 'src/index.jsx',
            external: ['react', 'react-dom'],
            output: {
                format: 'iife',
                globals: {
                    react:     'React',
                    'react-dom': 'ReactDOM',
                },
                entryFileNames: 'js/admin-agent.js',
                assetFileNames: 'css/admin-agent.css',
                // Prevent hash suffix on filenames
                chunkFileNames: 'js/[name].js',
            },
        },
    },

    test: {
        environment: 'jsdom',
        setupFiles:  ['tests/js/setup.js'],
        coverage: {
            provider:   'v8',
            reporter:   ['text', 'lcov'],
            thresholds: { lines: 60 },
        },
    },
});
