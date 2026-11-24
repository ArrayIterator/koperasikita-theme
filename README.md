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
- `languages/` — translation template and Indonesian translations.

## Optional integration

The theme can integrate with the TrayDigita Stack plugin when it is active. The
Stack is optional: without it the theme still loads and renders, and an admin
notice explains that some integrations are unavailable.

## License

GPLv2 or later.
