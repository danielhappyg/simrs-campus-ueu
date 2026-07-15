import { fileURLToPath, URL } from 'node:url';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        css: true,
        coverage: {
            provider: 'v8',
            reporter: ['text', 'lcov'],
            include: ['resources/js/components/**/*.{ts,tsx}'],
            exclude: ['resources/js/components/ui/**'],
        },
    },
});
