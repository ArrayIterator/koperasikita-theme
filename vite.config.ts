import { defineConfig } from 'vite';

export default defineConfig({
  server: {
    host: 'localhost',
    port: 5173,
    strictPort: true,
    cors: {
      origin: [
        'http://localhost:5173',
        'https://koperasikita.pentagonal.org',
        /^https?:\/\/([a-z0-9-]+\.)*test(:\d+)?$/,
        /^https?:\/\/([a-z0-9-]+\.)*local(:\d+)?$/,
      ],
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    manifest: true,
    rollupOptions: {
      input: {
        main: 'src/ts/main.ts',
        admin: 'src/ts/admin.ts',
        'customizer-preview': 'src/ts/customizer-preview.ts',
        app: 'src/css/app.css',
        'gold-markets': 'src/ts/gold-markets.ts',
        'gold-markets-css': 'src/css/gold-markets.css',
        weather: 'src/ts/weather.ts',
        'weather-css': 'src/css/weather.css',
        agenda: 'src/ts/agenda.ts',
        'agenda-css': 'src/css/agenda.css',
        pwa: 'src/ts/pwa.ts',
        'pwa-css': 'src/css/pwa.css',
      },
    },
  },
});
