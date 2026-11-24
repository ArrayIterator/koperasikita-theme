/**
 * Koperasi Kita — Customizer live preview.
 *
 * Binds the kk_palette_* colour settings, font and size settings, the
 * homepage layout, the dark-mode logo invert, the style variant and the
 * footer text to live CSS-variable / class / DOM updates in the preview
 * frame. Setting IDs must match inc/Customizer/Customizer.php exactly.
 */

declare const wp: {
	customize: (
		setting: string,
		callback: (value: { bind: (handler: (next: string | number | boolean) => void) => void }) => void,
	) => void;
};

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

/* One body class per font choice; see the body.font-* rules in app.css. */
const FONT_CLASSES: string[] = [
	'font-system',
	'font-georgia',
	'font-palatino',
	'font-trebuchet',
	'font-verdana',
	'font-arial',
];

const LAYOUT_CLASSES: string[] = ['kk-layout-grid', 'kk-layout-list'];

const VARIANT_CLASSES: string[] = ['kk-variant-classic', 'kk-variant-compact', 'kk-variant-bold'];

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

function applyFontFamily(next: SettingValue): void {
	const fontClass = `font-${String(next)}`;
	document.body.classList.remove(...FONT_CLASSES);
	if (FONT_CLASSES.includes(fontClass)) {
		document.body.classList.add(fontClass);
	}
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

	bindSetting('kk_font_family', applyFontFamily);

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
