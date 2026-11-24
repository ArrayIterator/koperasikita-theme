/**
 * Koperasi Kita — Customizer live preview.
 *
 * Binds the kk_palette_* colour settings, font and size settings and the
 * footer text to live CSS-variable / DOM updates in the preview frame.
 */

declare const wp: {
	customize: (
		setting: string,
		callback: (value: { bind: (handler: (next: string | number) => void) => void }) => void,
	) => void;
};

const PALETTE_SETTINGS: Array<{ setting: string; variable: string }> = [
	{ setting: 'kk_palette_green', variable: '--kk-green' },
	{ setting: 'kk_palette_green_dark', variable: '--kk-green-dark' },
	{ setting: 'kk_palette_green_light', variable: '--kk-green-light' },
	{ setting: 'kk_palette_gold', variable: '--kk-gold' },
	{ setting: 'kk_palette_gold_light', variable: '--kk-gold-light' },
	{ setting: 'kk_palette_ink', variable: '--kk-ink' },
	{ setting: 'kk_palette_muted', variable: '--kk-muted' },
	{ setting: 'kk_palette_paper', variable: '--kk-paper' },
	{ setting: 'kk_palette_surface', variable: '--kk-surface' },
	{ setting: 'kk_palette_border', variable: '--kk-border' },
];

function setRootVar(variable: string, value: string): void {
	document.documentElement.style.setProperty(variable, value);
}

function bindSetting(
	setting: string,
	handler: (value: string | number) => void,
): void {
	if (typeof wp === 'undefined' || !wp.customize) {
		return;
	}
	wp.customize(setting, (value) => {
		value.bind((next) => handler(next));
	});
}

function initCustomizerPreview(): void {
	PALETTE_SETTINGS.forEach(({ setting, variable }) => {
		bindSetting(setting, (next) => setRootVar(variable, String(next)));
	});

	bindSetting('kk_font_family', (next) => {
		document.body.classList.toggle('font-georgia', next === 'georgia' || next === 'system');
		document.body.classList.toggle('font-system', next === 'system');
	});

	bindSetting('kk_title_size', (next) => setRootVar('--kk-title-size', `${Number(next)}px`));
	bindSetting('kk_card_title_size', (next) => setRootVar('--kk-card-title-size', `${Number(next)}px`));
	bindSetting('kk_excerpt_size', (next) => setRootVar('--kk-excerpt-size', `${Number(next)}px`));

	bindSetting('kk_footer_text', (next) => {
		const footerText = document.querySelector('.kk-footer-text');
		if (footerText) {
			footerText.textContent = String(next);
		}
	});
}

initCustomizerPreview();
