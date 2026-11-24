# Koperasi Kita

A WordPress theme for the Koperasi Kita news site. Modern, clean, newsletter-style
editorial design: white paper background, cooperative green `#0B6B3A` primary,
gold accents.

## Requirements

- WordPress 6.5 or higher
- PHP 8.3 or higher
- Node 20+ and Bun 1.x (for the frontend build)

## Development

```sh
bun install
bun run dev      # Vite dev server on http://localhost:5173
bun run build    # production build into dist/
bun run typecheck
```

The production build output in `dist/` is committed, so a plain `git pull` on the
live site works without running a build there.

## Structure

- `functions.php` — bootstrap: PHP version guard, autoloader, Stack check, boot.
- `inc/Theme.php` — service wiring (theme support, sidebars, Customizer, assets).
- `inc/Setup/` — theme support, sidebars, widget registration, menu helpers.
- `inc/Assets/` — Vite manifest loader and enqueue logic.
- `inc/Customizer/` — the single "Koperasi Kita Theme Options" panel.
- `inc/Features/` — feature toggles and Phase 3 feature stubs.
- `inc/Icons/` — inline SVG icon set.
- `inc/SEO/` — meta, Open Graph and JSON-LD output.
- `inc/Breadcrumb/` — breadcrumb renderer.
- `inc/Images/` — featured image helpers.
- `src/ts/` — TypeScript entry points (built by Vite).
- `src/css/` — theme stylesheet source (built by Vite).
- `assets/img/` — logo files and the fallback placeholder.
- `assets/demo/` — branded placeholder images for the demo content.
- `demo-content/` — demo content export (`koperasikita-demo-content.xml`),
  importable with Tools → Import → WordPress (see "Demo content" below).
- `languages/` — translation template and Indonesian translations.

## Demo content

The theme ships with demo content (Indonesian, on-brand for a cooperative news
site) as a WordPress export file, so a fresh install can look like the demo in
minutes — no extra plugin needed, WordPress's built-in Import tool does it.

**What's inside** `demo-content/koperasikita-demo-content.xml`:

- 12 posts (Berita Koperasi, Ekonomi, Emas & Investasi, Edukasi, Anggota,
  Pengumuman) with real body copy, excerpts, categories and tags
- 5 pages: Tentang Kami, Keanggotaan, Kontak, Bookmark Saya, Kebijakan Privasi
- 3 agenda items (Rapat Anggota Tahunan, pelatihan, bazar UMKM)
- 3 menus: Menu Utama, Menu Bawah, Menu Footer
- 6 featured images (`assets/demo/*.jpg`, green/gold branded placeholders)

**How to import**

1. Deploy the theme first (the featured images are downloaded from the live
   site's theme folder, so the URLs must already resolve).
2. In wp-admin go to **Tools → Import → WordPress** (install the WordPress
   Importer plugin if prompted) and upload
   `demo-content/koperasikita-demo-content.xml`.
3. When asked, assign the posts to an existing author and check
   **Download and import file attachments** so the featured images are
   downloaded as media library attachments.
4. After the import, assign the menus to their locations in
   **Appearance → Menus → Manage Locations**: *Menu Utama* → Primary Menu and
   Mobile Bottom Menu, *Menu Footer* → Footer Menu. (WordPress exports do not
   carry menu locations, so this last step is manual.)

## Optional integration

The theme can integrate with the TrayDigita Stack plugin when it is active. The
Stack is optional: without it the theme still loads and renders, and an admin
notice explains that some integrations are unavailable.

## License

GPLv2 or later.
