import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        // Vite chạy trong container (service `node`), nên phải nghe ra ngoài loopback của container.
        // `origin` là địa chỉ trình duyệt dùng để gọi vite — đổi bằng VITE_ORIGIN khi app không mở
        // ở localhost.
        host: '0.0.0.0',
        port: 5173,
        origin: process.env.VITE_ORIGIN ?? 'http://localhost:5173',
        hmr: {
            host: process.env.VITE_HMR_HOST ?? 'localhost',
        },
        watch: {
            // Vite đăng ký một inotify watch cho mỗi file. Trong container, vendor/ (hàng chục
            // nghìn file của Filament) làm cạn hạn mức của host và giết dev server bằng ENOSPC.
            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
            ],
        },
    },
});
