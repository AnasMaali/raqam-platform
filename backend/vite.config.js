import tailwindcss from '@tailwindcss/vite';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

// The admin panel's theme only (the website is the Next.js app in frontend/). `npm run build` writes public/build,
// which is git-ignored: CI and the image build make it, and production never runs a dev server.
export default defineConfig({
    plugins: [
        laravel({ input: ['resources/css/filament/admin/theme.css'], refresh: false }),
        tailwindcss(),
    ],
});
