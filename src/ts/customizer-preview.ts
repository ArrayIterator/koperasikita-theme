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

const LAYOUT_CLASSES: string[] = ['kk-layout-grid', 'kk-layout-list'];

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
	const body = new URLSearchParams({ action: 'kk_download_font', font: id, nonce: data.nonce });
	const response = await fetch(data.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	});
	if (!response.ok) {
		throw new Error(`Font download request failed (${response.status}).`);
	}
	const json = (await response.json()) as {
		success?: boolean;
		data?: { cssUrl?: string; message?: string };
	};
	if (!json.success || typeof json.data?.cssUrl !== 'string' || json.data.cssUrl === '') {
		throw new Error(json.data?.message ?? 'Font download failed.');
	}
	return json.data.cssUrl;
}

/*
 * The local stylesheet is built by Fonts::buildLocalCss, whose first
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
	const layout = String(next) === 'list' ? 'list' : 'grid';
	const container = document.getElementById('kk-latest-items');
	if (container) {
		container.classList.remove(...LAYOUT_CLASSES);
		container.classList.add(`kk-layout-${layout}`);
	}
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
	document.querySelectorAll('.custom-logo, .kk-brand-mark').forEach((logo) => {
		logo.classList.toggle('is-inverted', inverted);
	});
}

function applyStyleVariant(next: SettingValue): void {
	const value = String(next);
	const variant = value === 'compact' || value === 'bold' ? value : 'classic';
	document.body.classList.remove(...VARIANT_CLASSES);
	document.body.classList.add(`kk-variant-${variant}`);
}

function initCustomizerPreview(): void {
	PALETTE_SETTINGS.forEach(({ setting, variable }) => {
		bindSetting(setting, (next) => setRootVar(variable, String(next)));
	});

	FONT_ROLES.forEach(bindFontRole);

	bindSetting('kk_title_size', (next) => setRootVar('--kk-title-size', `${Number(next)}px`));
	bindSetting('kk_card_title_size', (next) => setRootVar('--kk-card-title-size', `${Number(next)}px`));
	bindSetting('kk_excerpt_size', (next) => setRootVar('--kk-excerpt-size', `${Number(next)}px`));

	bindSetting('kk_homepage_layout', applyHomepageLayout);
	bindSetting('kk_logo_invert_dark', applyLogoInvert);
	bindSetting('kk_style_variant', applyStyleVariant);

	bindSetting('kk_footer_text', (next) => {
		const footerText = document.querySelector('.kk-footer-text');
		if (footerText) {
			footerText.textContent = String(next);
		}
	});
}

initCustomizerPreview();
