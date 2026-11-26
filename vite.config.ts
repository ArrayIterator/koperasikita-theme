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
      // NOTE: do NOT set output.format to 'iife' here — Rollup refuses it
      // with multiple inputs ("inlineDynamicImports" conflict). The entries
      // are ES-format bundles whose top-level names would leak into the
      // shared global scope if loaded as classic scripts (gold-markets'
      // `function I` vs main's `const I` threw "Identifier 'I' has already
      // been declared" and killed main.js on every page). The fix lives in
      // inc/Assets/Enqueue.php instead: EVERY JS entry is loaded as
      // type="module", so each entry runs in its own module scope and no
      // entry can ever collide with another. Never enqueue a JS entry as a
      // classic script.
      input: {
        main: 'src/ts/main.ts',
        admin: 'src/ts/admin.ts',
        'customizer-preview': 'src/ts/customizer-preview.ts',
        'customizer-controls': 'src/ts/customizer-controls.ts',
        'customizer-controls-css': 'src/css/customizer-controls.css',
        app: 'src/css/app.css',
        'tokens-css': 'src/css/tokens.css',
        'base-css': 'src/css/base.css',
        'header-css': 'src/css/header.css',
        'home-css': 'src/css/home.css',
        'cards-css': 'src/css/cards.css',
        'home-sections-css': 'src/css/home-sections.css',
        'content-css': 'src/css/content.css',
        'footer-css': 'src/css/footer.css',
        'overlays-css': 'src/css/overlays.css',
        'variants-css': 'src/css/variants.css',
        'responsive-css': 'src/css/responsive.css',
        'newsletter-css': 'src/css/newsletter.css',
        'gold-markets': 'src/ts/gold-markets.ts',
        'gold-markets-css': 'src/css/gold-markets.css',
        weather: 'src/ts/weather.ts',
        'weather-css': 'src/css/weather.css',
        agenda: 'src/ts/agenda.ts',
        'agenda-css': 'src/css/agenda.css',
        'article-font-size': 'src/ts/article-font-size.ts',
        pagination: 'src/ts/pagination.ts',
        pwa: 'src/ts/pwa.ts',
        'pwa-css': 'src/css/pwa.css',
        'setup-css': 'src/css/setup.css',
        'widget-blocks': 'src/ts/widget-blocks.ts',
        'widget-blocks-css': 'src/css/widget-blocks.css',
        'widgets-css': 'src/css/widgets.css',
        'editor-css': 'src/css/editor.css',
      },
    },
  },
});
