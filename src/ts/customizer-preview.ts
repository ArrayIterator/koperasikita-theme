/**
 * Koperasi Kita — Customizer live preview.
 *
 * Binds the kk_palette_* colour settings, font and size settings, the
 * homepage layout, the dark-mode logo invert, the style variant and the
 * footer text to live CSS-variable / class / DOM updates in the preview
 * frame. Setting IDs must match inc/Customizer/Customizer.php exactly.
 *
 * Fonts: the body and headings fonts are applied through the --kk-sans
 * and --kk-serif variables. A selected Google font needs its local
 * stylesheet (served from this site, never from Google) before the
 * family name can be used; the preview downloads it on demand through
 * the kk_download_font AJAX action and falls back to the system stack
 * while the download is pending or when it fails.
 */

export {};

import { postAjax } from './api';

interface CustomizeValue {
	bind: (handler: (next: string | number | boolean) => void) => void;
	get: () => string | number | boolean;
}

declare const wp: {
	customize: (setting: string, callback: (value: CustomizeValue) => void) => void;
};

/**
 * One catalogue entry as localized by Enqueue::registerCustomizerPreview.
 * `stack` already names the Google family when the font is downloaded;
 * until then it is the plain system fallback stack.
 */
interface PreviewFont {
	stack: string;
	cssUrl: string;
	google: boolean;
}

interface FontsPreviewData {
	ajaxUrl: string;
	nonce: string;
	fonts: Record<string, PreviewFont>;
}

declare const kkFontsPreview: FontsPreviewData | undefined;

type SettingValue = string | number | boolean;

const PALETTE_SETTINGS: Array<{ setting: string; variable: string }> = [
	{ setting: 'kk_palette_primary', variable: '--kk-green' },
	{ setting: 'kk_palette_primary_dark', variable: '--kk-green-dark' },
	{ setting: 'kk_palette_primary_light', variable: '--kk-green-light' },
	{ setting: 'kk_palette_gold', variable: '--kk-gold' },
	{ setting: 'kk_palette_gold_light', variable: '--kk-gold-light' },
	{ setting: 'kk_palette_ink', variable: '--kk-ink' },
	{ setting: 'kk_palette_muted', variable: '--kk-muted' },
	{ setting: 'kk_palette_paper', variable: '--kk-paper' },
	{ setting: 'kk_palette_surface', variable: '--kk-surface' },
	{ setting: 'kk_palette_border', variable: '--kk-border' },
];

const LAYOUT_CLASSES: string[] = ['kk-layout-grid', 'kk-layout-list', 'kk-layout-newsletter'];

const VARIANT_CLASSES: string[] = ['kk-variant-classic', 'kk-variant-compact', 'kk-variant-bold'];

/* One entry per font setting: setting id, CSS variable, preview link id. */
const FONT_ROLES: Array<{ setting: string; variable: string; linkId: string }> = [
	{ setting: 'kk_font_body', variable: '--kk-sans', linkId: 'kk-font-body' },
	{ setting: 'kk_font_headings', variable: '--kk-serif', linkId: 'kk-font-headings' },
];

/* Font id currently selected per setting; guards against stale downloads. */
const currentFont: Record<string, string> = {};

/* Font ids with a download already in flight; duplicates are skipped. */
const downloading = new Set<string>();

function previewData(): FontsPreviewData | null {
	if (typeof kkFontsPreview === 'undefined' || !kkFontsPreview) {
		return null;
	}
	return kkFontsPreview;
}

function setRootVar(variable: string, value: string): void {
	document.documentElement.style.setProperty(variable, value);
}

function bindSetting(setting: string, handler: (value: SettingValue) => void): void {
	if (typeof wp === 'undefined' || !wp.customize) {
		return;
	}
	wp.customize(setting, (value) => {
		value.bind((next) => handler(next));
	});
}

function ensureFontLink(linkId: string, href: string): void {
	const existing = document.getElementById(linkId);
	if (existing instanceof HTMLLinkElement) {
		existing.href = href;
		return;
	}
	const link = document.createElement('link');
	link.id = linkId;
	link.rel = 'stylesheet';
	link.href = href;
	document.head.appendChild(link);
}

function removeFontLink(linkId: string): void {
	document.getElementById(linkId)?.remove();
}

/*
 * Ask the server to download one Google font and return the URL of its
 * local stylesheet. Rejects with the server's message on failure.
 */
async function downloadFont(id: string): Promise<string> {
	const data = previewData();
	if (!data || !data.ajaxUrl || !data.nonce) {
		throw new Error('Font preview data is not available.');
	}
	const result = await postAjax<{ cssUrl?: string }>(
		{ ajaxUrl: data.ajaxUrl, nonce: data.nonce },
		'kk_download_font',
		{ font: id },
	);
	if (typeof result.cssUrl !== 'string' || result.cssUrl === '') {
		throw new Error('Font download failed.');
	}
	return result.cssUrl;
}

/*
 * The local stylesheet is built by FontDownloader::buildLocalCss, whose first
 * @font-face rule carries the family name. Reading it back from the
 * downloaded file is the only way the preview can name the family in
 * the CSS variable — the localized stack only names it once the font
 * is already downloaded. Returns an empty string when unreadable.
 */
async function familyFromCss(cssUrl: string): Promise<string> {
	const response = await fetch(cssUrl, { credentials: 'same-origin' });
	if (!response.ok) {
		return '';
	}
	const css = await response.text();
	const match = /font-family:\s*'([^']+)'/.exec(css);
	return match?.[1] ?? '';
}

function applyFont(setting: string, variable: string, linkId: string, id: string): void {
	const data = previewData();
	currentFont[setting] = id;

	const font = data?.fonts[id];
	if (!font) {
		return;
	}

	setRootVar(variable, font.stack);

	/* System fonts need no stylesheet; drop any link a previous pick left behind. */
	if (!font.google) {
		removeFontLink(linkId);
		return;
	}

	if (font.cssUrl !== '') {
		ensureFontLink(linkId, font.cssUrl);
		return;
	}

	/* Not downloaded yet: keep the fallback stack and fetch it in the background. */
	if (downloading.has(id)) {
		return;
	}
	downloading.add(id);

	void downloadFont(id)
		.then(async (cssUrl) => {
			font.cssUrl = cssUrl;
			try {
				const family = await familyFromCss(cssUrl);
				if (family !== '') {
					font.stack = `'${family.replace(/'/g, "\\'")}', ${font.stack}`;
				}
			} catch {
				/* Keep the fallback stack; the stylesheet still loads below. */
			}
			/* The user may have picked another font while this one downloaded. */
			if (currentFont[setting] !== id) {
				return;
			}
			setRootVar(variable, font.stack);
			ensureFontLink(linkId, cssUrl);
		})
		.catch((error: unknown) => {
			console.warn(`Could not download the "${id}" font for the preview; using the system fallback.`, error);
		})
		.finally(() => {
			downloading.delete(id);
		});
}

function bindFontRole(role: { setting: string; variable: string; linkId: string }): void {
	if (typeof wp === 'undefined' || !wp.customize) {
		return;
	}
	wp.customize(role.setting, (value) => {
		/* Apply the saved value on load, then keep following changes. */
		applyFont(role.setting, role.variable, role.linkId, String(value.get()));
		value.bind((next) => applyFont(role.setting, role.variable, role.linkId, String(next)));
	});
}

function applyHomepageLayout(next: SettingValue): void {
	const value = String(next);
	const layout = value === 'list' ? 'list' : value === 'newsletter' ? 'newsletter' : 'grid';
	const container = document.getElementById('kk-latest-items');
	if (container) {
		container.classList.remove(...LAYOUT_CLASSES);
		container.classList.add(`kk-layout-${layout}`);
	}
	document.querySelectorAll('.kk-newsletter-subscribe').forEach((block) => {
		block.toggleAttribute('hidden', layout !== 'newsletter');
	});
	document.querySelectorAll<HTMLButtonElement>('[data-kk-layout]').forEach((button) => {
		const active = button.dataset.kkLayout === layout;
		button.classList.toggle('is-active', active);
		button.setAttribute('aria-pressed', active ? 'true' : 'false');
	});
}

/*
 * The invert only takes effect in dark mode (see the .is-inverted rules
 * in app.css); the class itself is harmless in light mode, so toggling it
 * here previews exactly what the saved setting will do.
 */
function applyLogoInvert(next: SettingValue): void {
	const inverted = next === true || next === '1' || next === 'on';
	// The theme's own logo (K) is colourful and reads on light and dark,
	// so it is never inverted — only a user-uploaded custom logo is.
	document.querySelectorAll('.custom-logo, .kk-brand-mark:not(.kk-brand-mark--logo)').forEach((logo) => {
		logo.classList.toggle('is-inverted', inverted);
	});
}

function applyStyleVariant(next: SettingValue): void {
	const value = String(next);
	const variant = value === 'compact' || value === 'bold' ? value : 'classic';
	document.body.classList.remove(...VARIANT_CLASSES);
	document.body.classList.add(`kk-variant-${variant}`);
}

/*
 * Footer copyright preview. The copyright element carries the token values
 * (data-kk-footer-tokens, JSON) and the default line (data-kk-footer-default)
 * as data attributes, so the preview can replace {tokens} in the incoming
 * text exactly the way the server does — no extra localized data needed.
 * An empty text falls back to the default line, like the server render.
 */
function footerTokens(): Record<string, string> {
	const copyright = document.querySelector<HTMLElement>('.kk-copyright');
	if (!copyright) {
		return {};
	}
	const raw = copyright.dataset.kkFooterTokens ?? '';
	if (raw === '') {
		return {};
	}
	try {
		return JSON.parse(raw) as Record<string, string>;
	} catch {
		return {};
	}
}

function replaceFooterTokens(text: string, tokens: Record<string, string>): string {
	return text.replace(/\{(\w+)\}/g, (match: string, key: string) => tokens[key] ?? match);
}

function applyFooterText(next: SettingValue): void {
	const copyright = document.querySelector<HTMLElement>('.kk-copyright');
	if (!copyright) {
		return;
	}
	const text = String(next).trim() !== '' ? String(next) : (copyright.dataset.kkFooterDefault ?? '');
	copyright.textContent = replaceFooterTokens(text, footerTokens());
}

function applyFooterAlignment(next: SettingValue): void {
	const bottom = document.querySelector('.kk-footer-bottom');
	if (!bottom) {
		return;
	}
	const value = String(next);
	const align = value === 'center' || value === 'right' ? value : 'left';
	bottom.classList.remove('kk-footer-bottom--left', 'kk-footer-bottom--center', 'kk-footer-bottom--right');
	bottom.classList.add(`kk-footer-bottom--${align}`);
}

function initCustomizerPreview(): void {
	PALETTE_SETTINGS.forEach(({ setting, variable }) => {
		bindSetting(setting, (next) => setRootVar(variable, String(next)));
	});

	FONT_ROLES.forEach(bindFontRole);

	bindSetting('kk_title_size', (next) => setRootVar('--kk-title-size', `${Number(next)}px`));
	bindSetting('kk_single_title_size', (next) => setRootVar('--kk-single-title-size', `${Number(next)}px`));
	bindSetting('kk_card_title_size', (next) => setRootVar('--kk-card-title-size', `${Number(next)}px`));
	bindSetting('kk_excerpt_size', (next) => setRootVar('--kk-excerpt-size', `${Number(next)}px`));
	bindSetting('kk_mobile_menu_icon_size', (next) => setRootVar('--kk-mobile-menu-icon-size', `${Number(next)}px`));
	bindSetting('kk_mobile_menu_title_size', (next) => setRootVar('--kk-mobile-menu-title-size', `${Number(next)}px`));

	bindSetting('kk_homepage_layout', applyHomepageLayout);
	bindSetting('kk_logo_invert_dark', applyLogoInvert);
	bindSetting('kk_style_variant', applyStyleVariant);

	bindSetting('kk_footer_text', applyFooterText);
	bindSetting('kk_footer_alignment', applyFooterAlignment);
}

initCustomizerPreview();
