import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

const projectRoot = import.meta.dirname;

export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(projectRoot, 'resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        setupFiles: ['./resources/js/test/setup.ts'],
        css: false,
        include: ['resources/js/**/*.test.{ts,tsx}'],
    },
});
