/**
 * Koperasi Kita — admin script.
 *
 * Wires the Google Fonts management control rendered by
 * FontsManagementControl in the Typography section of the Customizer
 * (#kk-fonts-status). The download and clear buttons call the matching
 * AJAX actions and update the per-font status rows in place; the page
 * is never reloaded. On any other admin screen the container does not
 * exist and this file does nothing.
 *
 * Also wires the manual gold sync on Settings -> Gold Prices
 * ([data-kk-gold-sync]): one AJAX call per source, in order, with a
 * progress line per step and a page reload at the end so the status,
 * history list and chart refresh.
 *
 * The control renders one status row per selected Google font, in the
 * same order as the space-separated ids in the download button's
 * data-kk-fonts attribute, so rows are matched to fonts by position.
 *
 * The list also follows font changes: when the body or headings font
 * setting changes in the Customizer, the list is re-fetched from the
 * server (kk_fonts_status) and swapped in with the new selection —
 * no Customizer reload needed. The server renders the list, so it is
 * the source of truth; the same re-fetch runs after a download or a
 * clear, so a row the preview frame downloaded meanwhile shows up
 * as Ready too. Status strings come from the kkAdminData localize,
 * so they are translated in the admin language.
 */

import { postAjax } from './api';
import '../css/admin.css';

interface AdminStrings {
	ready: string;
	failed: string;
	notDownloaded: string;
	downloadFailed: string;
	adminDataMissing: string;
	syncingSource: string;
	syncStepDone: string;
	syncStepLatest: string;
	syncDone: string;
	loadingPreview: string;
	loadingChart: string;
	editKickerHint: string;
	editTitleHint: string;
	previewFailed: string;
	savingTemplate: string;
	saving: string;
	editDateLineHint: string;
	editStatHint: string;
	editHeaderHint: string;
	close: string;
	searching: string;
	noResults: string;
	showingOf: string;
}

interface AdminData {
	ajaxUrl: string;
	nonce: string;
	strings?: Partial<AdminStrings>;
	menuIcons?: MenuIconData;
}

interface MenuIconData {
	builtIn: Record<string, string>;
	noIcon: string;
	defaultColor: string;
}

/* One step of the manual gold sync (kk_logam_mulia_sync): the source
 * just synced, rows inserted vs skipped, its latest date, an error
 * (null on success), the next source to sync (null when done), and
 * the step position (step of total) for the progress bar. */
interface GoldSyncStep {
	source: string;
	inserted: number;
	skipped: number;
	latest_date: string | null;
	error: string | null;
	next: string | null;
	total?: number;
	step?: number;
}

interface CustomizeValue {
	bind: (handler: (next: string) => void) => void;
	get: () => string;
}

interface CustomizeApi {
	(setting: string, callback: (value: CustomizeValue) => void): void;
	(setting: string): CustomizeValue;
}

declare const wp: {
	customize: CustomizeApi;
};

declare const kkAdminData: AdminData | undefined;

/* English fallback when the localize carries no strings (old cache). */
const FALLBACK_STRINGS: AdminStrings = {
	ready: 'Ready',
	failed: 'Failed: %s',
	notDownloaded: 'Not downloaded yet',
	downloadFailed: 'Download failed.',
	adminDataMissing: 'Admin data is not available.',
	syncingSource: 'Scraping %s…',
	syncStepDone: '%s: inserted %s, skipped %s',
	syncStepLatest: ' (latest %s)',
	syncDone: 'Done — inserted %s, skipped %s (already in database). Reloading…',
	loadingPreview: 'Loading preview…',
	loadingChart: 'Loading chart…',
	editKickerHint: 'Click to edit the kicker.',
	editTitleHint: 'Click to edit the title.',
	previewFailed: 'Could not load the preview.',
	savingTemplate: 'Saving…',
	saving: 'Saving…',
	editDateLineHint: 'Click to edit the date line.',
	editStatHint: 'Click to edit this stat.',
	editHeaderHint: 'Click to edit this header.',
	close: 'Close',
	searching: 'Searching…',
	noResults: 'No results.',
	showingOf: 'Showing %s of %s — type to search.',
};

function adminData(): AdminData | null {
	if (typeof kkAdminData === 'undefined' || !kkAdminData) {
		return null;
	}
	if (!kkAdminData.ajaxUrl || !kkAdminData.nonce) {
		return null;
	}
	return kkAdminData;
}

function adminStrings(): AdminStrings {
	const strings = adminData()?.strings;
	return {
		ready: strings?.ready ?? FALLBACK_STRINGS.ready,
		failed: strings?.failed ?? FALLBACK_STRINGS.failed,
		notDownloaded: strings?.notDownloaded ?? FALLBACK_STRINGS.notDownloaded,
		downloadFailed: strings?.downloadFailed ?? FALLBACK_STRINGS.downloadFailed,
		adminDataMissing: strings?.adminDataMissing ?? FALLBACK_STRINGS.adminDataMissing,
		syncingSource: strings?.syncingSource ?? FALLBACK_STRINGS.syncingSource,
		syncStepDone: strings?.syncStepDone ?? FALLBACK_STRINGS.syncStepDone,
		syncStepLatest: strings?.syncStepLatest ?? FALLBACK_STRINGS.syncStepLatest,
		syncDone: strings?.syncDone ?? FALLBACK_STRINGS.syncDone,
		loadingPreview: strings?.loadingPreview ?? FALLBACK_STRINGS.loadingPreview,
		loadingChart: strings?.loadingChart ?? FALLBACK_STRINGS.loadingChart,
		editKickerHint: strings?.editKickerHint ?? FALLBACK_STRINGS.editKickerHint,
		editTitleHint: strings?.editTitleHint ?? FALLBACK_STRINGS.editTitleHint,
		previewFailed: strings?.previewFailed ?? FALLBACK_STRINGS.previewFailed,
		savingTemplate: strings?.savingTemplate ?? FALLBACK_STRINGS.savingTemplate,
		saving: strings?.saving ?? FALLBACK_STRINGS.saving,
		close: strings?.close ?? FALLBACK_STRINGS.close,
		editDateLineHint: strings?.editDateLineHint ?? FALLBACK_STRINGS.editDateLineHint,
		editStatHint: strings?.editStatHint ?? FALLBACK_STRINGS.editStatHint,
		editHeaderHint: strings?.editHeaderHint ?? FALLBACK_STRINGS.editHeaderHint,
		searching: strings?.searching ?? FALLBACK_STRINGS.searching,
		noResults: strings?.noResults ?? FALLBACK_STRINGS.noResults,
		showingOf: strings?.showingOf ?? FALLBACK_STRINGS.showingOf,
	};
}

/* Fill %s placeholders in order (one replacement per argument). */
function formatTemplate(template: string, ...args: Array<string | number>): string {
	let out = template;
	for (const arg of args) {
		out = out.replace('%s', String(arg));
	}
	return out;
}

function errorMessage(error: unknown): string {
	return error instanceof Error && error.message !== '' ? error.message : adminStrings().downloadFailed;
}

function formatFailed(message: string): string {
	return adminStrings().failed.replace('%s', message);
}

/*
 * Replace the status text of one row, keeping its <strong> label.
 * Rows render as "<strong>Label</strong>: Status", so rebuilding the
 * text node after the label is enough.
 */
function setRowStatus(row: HTMLLIElement, status: string): void {
	const label = row.querySelector('strong');
	row.textContent = '';
	if (label) {
		row.appendChild(label);
	}
	row.appendChild(document.createTextNode(`: ${status}`));
}

function setButtonsDisabled(downloadButton: HTMLButtonElement, clearButton: HTMLButtonElement, disabled: boolean): void {
	downloadButton.disabled = disabled;
	clearButton.disabled = disabled;
}

/*
 * The font ids the Customizer currently holds for the two font
 * settings — including unsaved changes, which the server cannot see
 * yet. Null outside the Customizer (widgets screen), where
 * wp.customize does not exist.
 */
function currentFontIds(): { body: string; headings: string } | null {
	if (typeof wp === 'undefined' || !wp.customize) {
		return null;
	}
	const body = wp.customize('kk_font_body');
	const headings = wp.customize('kk_font_headings');
	if (!body || !headings) {
		return null;
	}
	const bodyId = body.get();
	const headingsId = headings.get();
	if (typeof bodyId !== 'string' || typeof headingsId !== 'string') {
		return null;
	}
	return { body: bodyId, headings: headingsId };
}

/*
 * Monotonic token so overlapping refreshes cannot resolve out of
 * order: only the newest refresh may swap the list in.
 */
let refreshToken = 0;

/*
 * Re-fetch the status list from the server and swap it in, then
 * re-wire the buttons — the server renders the list, so the markup
 * (rows, button data, translated strings) is always current.
 */
async function refreshStatus(container: HTMLElement): Promise<void> {
	const config = adminData();
	if (!config) {
		return;
	}
	const token = ++refreshToken;
	const fonts = currentFontIds();
	const params: Record<string, string> = fonts ? { body: fonts.body, headings: fonts.headings } : {};
	try {
		const data = await postAjax<{ html: string }>(config, 'kk_fonts_status', params);
		if (token !== refreshToken) {
			return;
		}
		if (typeof data.html !== 'string') {
			return;
		}
		container.innerHTML = data.html;
		bindFontsControls(container);
	} catch (error) {
		console.warn('Could not refresh the font status list.', error);
	}
}

async function downloadFonts(
	container: HTMLElement,
	downloadButton: HTMLButtonElement,
	clearButton: HTMLButtonElement,
	ids: string[],
	rows: HTMLLIElement[],
): Promise<void> {
	setButtonsDisabled(downloadButton, clearButton, true);
	try {
		const config = adminData();
		if (!config) {
			throw new Error(adminStrings().adminDataMissing);
		}
		const strings = adminStrings();
		for (let i = 0; i < ids.length; i += 1) {
			const id = ids[i] as string;
			const row = rows[i];
			try {
				await postAjax(config, 'kk_download_font', { font: id });
				if (row) {
					setRowStatus(row, strings.ready);
				}
			} catch (error) {
				if (row) {
					setRowStatus(row, formatFailed(errorMessage(error)));
				}
			}
		}
		/* Server state is the truth — re-fetch so the list matches it. */
		await refreshStatus(container);
	} finally {
		setButtonsDisabled(downloadButton, clearButton, false);
	}
}

async function clearFonts(
	container: HTMLElement,
	downloadButton: HTMLButtonElement,
	clearButton: HTMLButtonElement,
	rows: HTMLLIElement[],
): Promise<void> {
	setButtonsDisabled(downloadButton, clearButton, true);
	try {
		const config = adminData();
		if (!config) {
			throw new Error(adminStrings().adminDataMissing);
		}
		await postAjax(config, 'kk_clear_fonts');
		rows.forEach((row) => setRowStatus(row, adminStrings().notDownloaded));
		/* Server state is the truth — re-fetch so the list matches it. */
		await refreshStatus(container);
	} catch (error) {
		console.warn('Could not clear the downloaded fonts.', error);
	} finally {
		setButtonsDisabled(downloadButton, clearButton, false);
	}
}

function bindFontsControls(container: HTMLElement): void {
	const downloadButton = container.querySelector<HTMLButtonElement>('[data-kk-font-action="download"]');
	const clearButton = container.querySelector<HTMLButtonElement>('[data-kk-font-action="clear"]');
	if (!downloadButton || !clearButton) {
		return;
	}

	const ids = (downloadButton.dataset.kkFonts ?? '').split(/\s+/).filter((id) => id !== '');
	const rows = Array.from(container.querySelectorAll('li'));

	/* Nothing to download when no Google font is selected. */
	downloadButton.disabled = ids.length === 0;

	downloadButton.addEventListener('click', () => {
		void downloadFonts(container, downloadButton, clearButton, ids, rows);
	});
	clearButton.addEventListener('click', () => {
		void clearFonts(container, downloadButton, clearButton, rows);
	});
}

/*
 * When either font setting changes in the Customizer, re-fetch the
 * list with the new selection. Outside the Customizer wp.customize
 * does not exist and there is nothing to watch.
 */
function watchFontSettings(container: HTMLElement): void {
	if (typeof wp === 'undefined' || !wp.customize) {
		return;
	}
	for (const setting of ['kk_font_body', 'kk_font_headings']) {
		wp.customize(setting, (value) => {
			value.bind(() => {
				void refreshStatus(container);
			});
		});
	}
}

function initAdmin(): void {
	/* One broken widget must never kill the rest: the gold sync
	 * button once sat dead with no error at all because an
	 * earlier init threw before initGoldSync ran. Each init is
	 * independent, so a failure is logged and the rest still run. */
	const inits = [
		initTermPickers,
		initProviderPickers,
		initMenuIconPickers,
		initAgendaWidgetFields,
		initGoldSync,
		initGoldSummaryChart,
		initDataDeskPreview,
		initDataDeskAjaxSave,
		initDataDeskWidgetPreview,
		initDataDeskPopupPreview,
		initSidebarSettingsPopup,
	];

	for (const init of inits) {
		try {
			init();
		} catch (error) {
			console.error(error);
		}
	}

	if (!adminData()) {
		return;
	}

	const container = document.getElementById('kk-fonts-status');
	if (!container) {
		return;
	}

	bindFontsControls(container);
	watchFontSettings(container);
}

/* The site colour scheme for the Data Desk 'auto' palette, resolved
 * the same way as the frontend (main.ts): the stored preference
 * (localStorage 'kk-color-scheme') wins, otherwise the OS scheme.
 * The frontend sets html[data-color-scheme]; the admin does not, so
 * the preview containers carry the attribute instead. */
function resolveAdminScheme(): 'light' | 'dark' {
	const stored = window.localStorage.getItem('kk-color-scheme');
	if (stored === 'light' || stored === 'dark') {
		return stored;
	}
	return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

/**
 * Live Data Desk preview on the Data Desk Templates form.
 *
 * The form is a top bar (name, kind, symbol/days or brands,
 * palette, Save) over the live preview — and the preview IS the
 * editor: the kicker and title are edited inline there. The preview
 * shows the real panel / table markup with sample data (the server
 * renders the first one). Every field change — kind, symbol, days,
 * brands, palette, title, kicker, stat texts, hidden blocks —
 * re-renders it through kk_data_desk_preview, debounced so typing
 * does not fire a request per keystroke. The brands picker is a chip
 * UI over the hidden select and never fires `change` on it, so clicks
 * in its wrapper refresh too. The palette is a segmented control of
 * radios synced into the hidden #kk-template-palette input. A failed
 * refresh keeps the last good preview and reports the error under
 * the box.
 *
 * The preview kicker, title and date line are edited inline:
 * none has a visible form row (all are hidden inputs) — the
 * preview IS the editor. Clicking any of the three texts edits it
 * in place; Enter or blur commits into the hidden field and
 * refreshes the preview, Escape cancels and restores the text.
 * The three stat blocks work the same way (their label and note
 * write into hidden stat_* fields), and each carries an eye
 * toggle that hides the block (dimmed in the editor, gone on the
 * frontend) or shows it again. A table template's three column
 * headers work the same way again (they write into hidden
 * header_* fields); clearing a header means empty (the default
 * label). The container carries
 * data-color-scheme so the 'auto' palette follows the site scheme
 * in the admin preview too.
 */
function initDataDeskPreview(): void {
	const config = adminData();
	if (!config) {
		return;
	}
	const container = document.querySelector<HTMLElement>('[data-kk-data-desk-preview]');
	if (!container) {
		return;
	}
	const kind = document.getElementById('kk-template-kind') as HTMLSelectElement | null;
	const symbol = document.getElementById('kk-template-symbol') as HTMLSelectElement | null;
	const days = document.getElementById('kk-template-days') as HTMLInputElement | null;
	const brands = document.getElementById('kk-template-brands') as HTMLSelectElement | null;
	const palette = document.getElementById('kk-template-palette') as HTMLInputElement | null;
	const title = document.getElementById('kk-template-title') as HTMLInputElement | null;
	const kicker = document.getElementById('kk-template-kicker') as HTMLInputElement | null;
	const dateLine = document.getElementById('kk-template-date-line') as HTMLInputElement | null;
	const hiddenBlocks = document.getElementById('kk-template-hidden-blocks') as HTMLInputElement | null;
	const placeholderDateFormat = document.getElementById('kk-template-placeholder-date-format') as HTMLInputElement | null;
	const placeholderPercent = document.getElementById('kk-template-placeholder-percent') as HTMLInputElement | null;
	const placeholderZoneColor = document.getElementById('kk-template-placeholder-zone-color') as HTMLInputElement | null;
	if (!kind || !symbol || !days || !brands || !palette || !title || !kicker || !dateLine || !hiddenBlocks || !placeholderDateFormat || !placeholderPercent || !placeholderZoneColor) {
		return;
	}
	/* Per-block stat texts, one hidden input per label + sub-line.
	 * The keys match the data-kk-stat-field attributes the panel
	 * template renders (sell-label, sell-sub, ...). */
	const statBlocks = ['sell', 'buyback', 'performance'];
	const statInputs: Record<string, HTMLInputElement> = {};
	for (const block of statBlocks) {
		for (const field of ['label', 'sub']) {
			const input = document.getElementById(`kk-template-stat-${block}-${field}`) as HTMLInputElement | null;
			if (!input) {
				return;
			}
			statInputs[`${block}-${field}`] = input;
		}
	}
	/* Per-header table texts, one hidden input per column. The
	 * keys match the data-kk-header-field attributes the table
	 * template renders (brand, price, updated). */
	const headerFields = ['brand', 'price', 'updated'];
	const headerInputs: Record<string, HTMLInputElement> = {};
	for (const field of headerFields) {
		const input = document.getElementById(`kk-template-header-${field}`) as HTMLInputElement | null;
		if (!input) {
			return;
		}
		headerInputs[field] = input;
	}

	const status = document.createElement('p');
	status.className = 'description';
	container.after(status);

	let timer: number | null = null;
	let lastGood = container.innerHTML;

	/* The 'auto' palette reads this attribute (see the --auto rules
	 * in gold-markets.css); the refresh replaces the inner HTML, not
	 * the container, so it survives re-renders. */
	container.setAttribute('data-color-scheme', resolveAdminScheme());

	/* The discoverability hint for the inline editing (a title
	 * tooltip on each editable text). Re-applied after every
	 * refresh, which replaces the preview DOM. */
	const markEditable = (): void => {
		const kickerEl = container.querySelector('.kk-data-desk-kicker');
		if (kickerEl) {
			kickerEl.setAttribute('title', adminStrings().editKickerHint);
		}
		const titleEl = container.querySelector('.kk-data-desk-title');
		if (titleEl) {
			titleEl.setAttribute('title', adminStrings().editTitleHint);
		}
		const dateLineEl = container.querySelector('.kk-data-desk-subtitle[data-kk-date-line]');
		if (dateLineEl) {
			dateLineEl.setAttribute('title', adminStrings().editDateLineHint);
		}
		container.querySelectorAll('[data-kk-stat-field]').forEach((el) => {
			el.setAttribute('title', adminStrings().editStatHint);
		});
		container.querySelectorAll('[data-kk-header-field]').forEach((el) => {
			el.setAttribute('title', adminStrings().editHeaderHint);
		});
	};

	const refresh = (): void => {
		if (timer !== null) {
			window.clearTimeout(timer);
			timer = null;
		}
		timer = window.setTimeout(() => {
			const params: Record<string, string> = {
				kind: kind.value,
				symbol: symbol.value,
				days: days.value,
				palette: palette.value,
				title: title.value,
				kicker: kicker.value,
				date_line: dateLine.value,
				brands: Array.from(brands.selectedOptions)
					.map((option) => option.value)
					.join(','),
				hidden_blocks: hiddenBlocks.value,
				placeholder_date_format: placeholderDateFormat.value,
				placeholder_percent: placeholderPercent.value,
				placeholder_zone_color: placeholderZoneColor.value,
			};
			for (const [key, input] of Object.entries(statInputs)) {
				params[`stat_${key.replace('-', '_')}`] = input.value;
			}
			for (const [key, input] of Object.entries(headerInputs)) {
				params[`header_${key}`] = input.value;
			}
			container.classList.add('is-loading');
			status.textContent = adminStrings().loadingPreview;
			void postAjax<{ html: string }>(config, 'kk_data_desk_preview', params)
				.then((data) => {
					container.innerHTML = data.html;
					lastGood = data.html;
					status.textContent = '';
					markEditable();
				})
				.catch((error: unknown) => {
					container.innerHTML = lastGood;
					markEditable();
					status.textContent = errorMessage(error);
				})
				.finally(() => {
					container.classList.remove('is-loading');
				});
		}, 300);
	};

	/* Inline editing of the preview kicker + title + date line +
	 * stat texts, delegated on the container because every refresh
	 * replaces the preview DOM. Click to edit (text selected),
	 * Enter or blur commits the text into the hidden field and
	 * refreshes the preview, Escape cancels and restores the
	 * previous text. Clearing the title means empty (automatic
	 * title); clearing the kicker means empty (default 'Meja
	 * Data'); clearing the date line means empty (the computed
	 * date range); clearing a stat label or note means empty (the
	 * computed text). */
	let editing: HTMLElement | null = null;
	let editingOriginal = '';

	/* Which hidden input an inline-edited element writes into:
	 * kicker / title / date line by class, a stat label or note
	 * by its data-kk-stat-field attribute, a table header by its
	 * data-kk-header-field attribute. */
	const fieldForEdit = (el: HTMLElement): HTMLInputElement | null => {
		if (el.classList.contains('kk-data-desk-kicker')) {
			return kicker;
		}
		if (el.classList.contains('kk-data-desk-title')) {
			return title;
		}
		if (el.classList.contains('kk-data-desk-subtitle')) {
			return dateLine;
		}
		const statField = el.getAttribute('data-kk-stat-field');
		if (statField) {
			return statInputs[statField] ?? null;
		}
		const headerField = el.getAttribute('data-kk-header-field');
		return headerField ? headerInputs[headerField] ?? null : null;
	};

	const finishEdit = (commit: boolean): void => {
		if (!editing) {
			return;
		}
		const el = editing;
		editing = null;
		const field = fieldForEdit(el);
		if (commit && field) {
			field.value = el.textContent?.trim() ?? '';
		} else {
			el.textContent = editingOriginal;
		}
		el.removeAttribute('contenteditable');
		el.classList.remove('is-editing');
		el.blur();
		if (commit && field) {
			refresh();
		}
	};

	container.addEventListener('click', (event) => {
		const target = (event.target as HTMLElement | null)?.closest?.(
			'.kk-data-desk-kicker, .kk-data-desk-title, .kk-data-desk-subtitle[data-kk-date-line], .kk-data-desk-stat-label[data-kk-stat-field], .kk-data-desk-stat-note[data-kk-stat-field], th[data-kk-header-field]',
		) as HTMLElement | null;
		if (!target || editing === target) {
			return;
		}
		/* A pending refresh would replace the node mid-edit. */
		if (timer !== null) {
			window.clearTimeout(timer);
			timer = null;
		}
		if (editing) {
			finishEdit(true);
		}
		editing = target;
		editingOriginal = target.textContent ?? '';
		target.setAttribute('contenteditable', 'true');
		target.classList.add('is-editing');
		target.focus();
		window.getSelection()?.selectAllChildren(target);
	});

	container.addEventListener('keydown', (event) => {
		if (!editing) {
			return;
		}
		if (event.key === 'Enter') {
			event.preventDefault();
			finishEdit(true);
		} else if (event.key === 'Escape') {
			event.preventDefault();
			finishEdit(false);
		}
	});

	/* Blur commits: a click on the other editable text blurs first
	 * (committing it) and the click handler then starts the new
	 * edit, so the two paths never double-commit. */
	container.addEventListener('focusout', () => {
		if (editing) {
			finishEdit(true);
		}
	});

	/* Eye toggle on each stat block: hides the block on the
	 * frontend, dims it in the editor so it can be un-hidden.
	 * The hidden list lives in #kk-template-hidden-blocks; the
	 * block dims immediately and the debounced refresh re-renders
	 * the preview (which swaps the eye icon) from the server. */
	container.addEventListener('click', (event) => {
		const button = (event.target as HTMLElement | null)?.closest?.(
			'[data-kk-stat-toggle]',
		) as HTMLButtonElement | null;
		if (!button) {
			return;
		}
		/* A pending refresh would replace the node mid-toggle. */
		if (timer !== null) {
			window.clearTimeout(timer);
			timer = null;
		}
		const key = button.getAttribute('data-kk-stat-toggle') ?? '';
		if (!statBlocks.includes(key)) {
			return;
		}
		const hidden = hiddenBlocks.value
			.split(',')
			.map((part) => part.trim())
			.filter((part) => part !== '');
		const at = hidden.indexOf(key);
		if (at >= 0) {
			hidden.splice(at, 1);
		} else {
			hidden.push(key);
		}
		hiddenBlocks.value = hidden.join(',');
		container
			.querySelector(`[data-kk-stat-block="${key}"]`)
			?.classList.toggle('kk-data-desk-stat--hidden', at < 0);
		refresh();
	});

	[kind, symbol, days, palette].forEach((el) => {
		el.addEventListener('change', refresh);
	});
	/* The palette is a segmented control in the top bar (radios),
	 * synced into the hidden #kk-template-palette input the
	 * refresh reads. A segment click updates the hidden value and
	 * fires its change listener, so the preview refreshes. */
	document.querySelectorAll<HTMLInputElement>('input[name="kk_template_palette_ui"]').forEach((radio) => {
		radio.addEventListener('change', () => {
			palette.value = radio.value;
			palette.dispatchEvent(new Event('change'));
		});
	});
	/* Placeholders: the section under the preview lists the tokens
	 * with an Insert button each, and the settings popup holds the
	 * three choices (date format, percent type, zone colour).
	 * Insert goes into the text being edited inline, or into the
	 * date line when nothing is. A choice writes into its hidden
	 * field and refreshes the preview at once. */
	const placeholderSection = document.getElementById('kk-data-desk-placeholders');
	const placeholderPopup = document.getElementById('kk-data-desk-placeholders-popup');
	if (placeholderSection && placeholderPopup) {
		const closeButton = placeholderPopup.querySelector<HTMLButtonElement>(
			'[data-kk-placeholder-popup-close]',
		);
		let placeholderOpener: HTMLElement | null = null;
		const closePlaceholderPopup = (): void => {
			placeholderPopup.hidden = true;
			placeholderOpener?.focus();
			placeholderOpener = null;
		};
		placeholderSection.querySelectorAll<HTMLButtonElement>('[data-kk-placeholder-insert]').forEach((button) => {
			button.addEventListener('mousedown', (event) => {
				/* Keep the focus (and selection) in the text being
				 * edited: a plain click would blur it first and the
				 * edit would commit before the token lands. */
				event.preventDefault();
				const token = button.getAttribute('data-kk-placeholder-insert') ?? '';
				if (token === '') {
					return;
				}
				if (editing) {
					const selection = window.getSelection();
					if (selection && selection.rangeCount > 0) {
						const range = selection.getRangeAt(0);
						range.deleteContents();
						range.insertNode(document.createTextNode(token));
						range.collapse(false);
						selection.removeAllRanges();
						selection.addRange(range);
					} else {
						editing.append(document.createTextNode(token));
					}
					return;
				}
				dateLine.value = dateLine.value === '' ? token : `${dateLine.value} ${token}`;
				refresh();
			});
		});
		placeholderSection.querySelector('[data-kk-placeholder-configure]')?.addEventListener('click', (event) => {
			placeholderOpener = event.currentTarget as HTMLElement;
			placeholderPopup.hidden = false;
			closeButton?.focus();
		});
		placeholderPopup.querySelectorAll('[data-kk-placeholder-popup-close]').forEach((el) => {
			el.addEventListener('click', closePlaceholderPopup);
		});
		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && !placeholderPopup.hidden) {
				closePlaceholderPopup();
			}
		});
		const placeholderChoice = (name: string, input: HTMLInputElement): void => {
			document.querySelectorAll<HTMLInputElement>(`input[name="${name}"]`).forEach((radio) => {
				radio.addEventListener('change', () => {
					input.value = radio.value;
					refresh();
				});
			});
		};
		placeholderChoice('kk_placeholder_date_format_ui', placeholderDateFormat);
		placeholderChoice('kk_placeholder_percent_ui', placeholderPercent);
		placeholderChoice('kk_placeholder_zone_color_ui', placeholderZoneColor);
	}
	[days, title, kicker].forEach((el) => {
		el.addEventListener('input', refresh);
	});
	brands.addEventListener('change', refresh);
	brands.parentElement?.querySelector('.kk-term-picker')?.addEventListener('click', refresh);
	markEditable();
}

/**
 * A floating toast notice (top-right, below the WP admin bar):
 * success is KK green, errors are red. Auto-dismisses after a few
 * seconds; the close button dismisses it at once. One toast at a
 * time — a new one replaces the old.
 */
function showToast(message: string, kind: 'success' | 'error'): void {
	document.querySelector('.kk-toast')?.remove();
	const toast = document.createElement('div');
	toast.className = `kk-toast kk-toast--${kind}`;
	toast.setAttribute('role', 'status');
	const text = document.createElement('span');
	text.className = 'kk-toast-message';
	text.textContent = message;
	const close = document.createElement('button');
	close.type = 'button';
	close.className = 'kk-toast-close';
	close.setAttribute('aria-label', adminStrings().close);
	close.textContent = '×';
	close.addEventListener('click', () => {
		toast.remove();
	});
	toast.append(text, close);
	document.body.appendChild(toast);
	window.setTimeout(() => {
		toast.remove();
	}, 4000);
}

/**
 * AJAX save for the Data Desk template form.
 *
 * The top-bar Save button submits the form through
 * kk_data_desk_save_template instead of a native POST, so the page
 * never reloads or redirects (the native redirect used to blank
 * the page — see DataDeskTemplatesPage::handleActionsOnLoad). While
 * saving the button is disabled with a "Saving…" label; on success
 * a floating toast confirms and the hidden template id + the URL
 * are updated to the saved id, so a second save updates the same
 * template (matters for new templates, which get their id from
 * the server). On failure the toast shows the server's message.
 */
function initDataDeskAjaxSave(): void {
	const config = adminData();
	if (!config) {
		return;
	}
	const form = document.getElementById('kk-data-desk-template-form') as HTMLFormElement | null;
	if (!form) {
		return;
	}
	const submit = form.querySelector<HTMLInputElement>('input[type="submit"]');
	const idInput = form.querySelector<HTMLInputElement>('input[name="kk_template_id"]');
	if (!submit || !idInput) {
		return;
	}
	const originalValue = submit.value;
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		if (submit.disabled) {
			return;
		}
		submit.disabled = true;
		submit.value = adminStrings().savingTemplate;
		const formData = new FormData(form);
		/* Every field in the form, not a fixed list: the stat
		 * labels + sub-lines, the table headers, the date line
		 * and the hidden-blocks list are hidden inputs the save
		 * must carry, and a fixed list silently dropped them
		 * (the save reported success while the overrides
		 * reverted on reload). Brands stay a comma list: the
		 * shared reader accepts an array or a comma list. */
		const params: Record<string, string> = {};
		formData.forEach((value, key) => {
			if (key === 'kk_template_brands[]' || typeof value !== 'string') {
				return;
			}
			params[key] = value;
		});
		params.kk_template_brands = formData.getAll('kk_template_brands[]').map(String).join(',');
		void postAjax<{ id: string; message: string }>(config, 'kk_data_desk_save_template', params)
			.then((data) => {
				idInput.value = data.id;
				const url = new URL(window.location.href);
				url.searchParams.set('action', 'edit');
				url.searchParams.set('id', data.id);
				url.searchParams.delete('saved');
				window.history.replaceState(null, '', url.toString());
				showToast(data.message, 'success');
			})
			.catch((error: unknown) => {
				showToast(errorMessage(error), 'error');
			})
			.finally(() => {
				submit.disabled = false;
				submit.value = originalValue;
			});
	});
}

/**
 * Layout preview in the classic Data Desk / Gold Data Desk widget
 * forms (widgets.php, Customizer). Each form renders its preview
 * server-side; changing the template select re-renders it through
 * kk_data_desk_preview by template id. Widget forms re-render after
 * a save, so new boxes are picked up by a MutationObserver, the
 * same pattern initTermPickers uses. A failed refresh keeps the last
 * good preview and reports the error under the box.
 */
function initDataDeskWidgetPreview(): void {
	const config = adminData();
	if (!config) {
		return;
	}

	const bindOne = (box: HTMLElement): void => {
		if (box.dataset.kkPreviewInit === '1') {
			return;
		}
		box.dataset.kkPreviewInit = '1';

		const body = box.querySelector<HTMLElement>('[data-kk-data-desk-widget-preview-body]');
		const select = box
			.closest('form')
			?.querySelector<HTMLSelectElement>('[data-kk-data-desk-template-select]');
		if (!body || !select) {
			return;
		}

		/* Same 'auto' palette support as the templates preview: the
		 * box carries the resolved scheme (see resolveAdminScheme). */
		box.setAttribute('data-color-scheme', resolveAdminScheme());

		const status = document.createElement('p');
		status.className = 'description';
		box.after(status);

		let lastGood = body.innerHTML;

		select.addEventListener('change', () => {
			box.classList.add('is-loading');
			status.textContent = adminStrings().loadingPreview;
			void postAjax<{ html: string }>(config, 'kk_data_desk_preview', { template: select.value })
				.then((data) => {
					body.innerHTML = data.html;
					lastGood = data.html;
					status.textContent = '';
				})
				.catch((error: unknown) => {
					body.innerHTML = lastGood;
					status.textContent = errorMessage(error);
				})
				.finally(() => {
					box.classList.remove('is-loading');
				});
		});
	};

	const scan = (root: ParentNode): void => {
		root.querySelectorAll<HTMLElement>('[data-kk-data-desk-widget-preview]').forEach(bindOne);
	};

	scan(document);

	const observer = new MutationObserver((mutations) => {
		for (const mutation of Array.from(mutations)) {
			for (const node of Array.from(mutation.addedNodes)) {
				if (node instanceof HTMLElement) {
					scan(node);
				}
			}
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
}

/**
 * Template preview popup on the Data Desk Templates list.
 *
 * The list shows no inline preview — each row's Preview link opens
 * one shared centered popup (blurred backdrop, same pattern as the
 * menu icon picker) and fetches the real panel / table markup with
 * sample data through kk_data_desk_preview by template id. Close on
 * Close, backdrop click, or Escape; focus returns to the link that
 * opened it. A failed fetch shows an honest error line; a template
 * deleted since the list loaded answers "Template not found." from
 * the server. A second open while a fetch is in flight wins — a
 * stale response never overwrites the newer popup.
 */
function initDataDeskPopupPreview(): void {
	const config = adminData();
	if (!config) {
		return;
	}
	const popup = document.getElementById('kk-data-desk-popup');
	if (!popup) {
		return;
	}
	const body = popup.querySelector<HTMLElement>('[data-kk-data-desk-popup-body]');
	const title = popup.querySelector<HTMLElement>('#kk-data-desk-popup-title');
	const closeButton = popup.querySelector<HTMLButtonElement>('button[data-kk-data-desk-popup-close]');
	if (!body || !title || !closeButton) {
		return;
	}

	let opener: HTMLAnchorElement | null = null;
	let request = 0;

	const close = (): void => {
		popup.hidden = true;
		if (opener) {
			opener.focus();
			opener = null;
		}
	};

	const open = (link: HTMLAnchorElement): void => {
		const id = link.dataset.kkTemplateId ?? '';
		if (id === '') {
			return;
		}
		opener = link;
		title.textContent = link.dataset.kkTemplateName ?? '';
		body.classList.add('is-loading');
		body.innerHTML = '';
		const loading = document.createElement('p');
		loading.className = 'description';
		loading.textContent = adminStrings().loadingPreview;
		body.append(loading);
		popup.hidden = false;
		closeButton.focus();

		const mine = ++request;
		void postAjax<{ html: string }>(config, 'kk_data_desk_preview', { template: id })
			.then((data) => {
				if (mine !== request) {
					return;
				}
				body.innerHTML = data.html;
			})
			.catch((error: unknown) => {
				if (mine !== request) {
					return;
				}
				const detail = error instanceof Error && error.message !== '' ? error.message : '';
				const failed = document.createElement('p');
				failed.textContent =
					detail === ''
						? adminStrings().previewFailed
						: `${adminStrings().previewFailed} ${detail}`;
				body.innerHTML = '';
				body.append(failed);
			})
			.finally(() => {
				if (mine === request) {
					body.classList.remove('is-loading');
				}
			});
	};

	document
		.querySelectorAll<HTMLAnchorElement>('[data-kk-data-desk-preview-link]')
		.forEach((link) => {
			link.addEventListener('click', (event) => {
				event.preventDefault();
				open(link);
			});
		});

	popup.querySelectorAll('[data-kk-data-desk-popup-close]').forEach((el) => {
		el.addEventListener('click', close);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !popup.hidden) {
			close();
		}
	});
}

/**
 * Sidebar Settings popup on the Setup page.
 *
 * The "Sidebar Settings" link in the Quick start card opens this
 * popup instead of navigating to the full settings page (the link
 * keeps its href as the no-JS fallback). The form posts the checked
 * sticky sidebars through kk_sidebar_settings_save, so the page
 * never reloads: on success a floating toast confirms and the
 * popup closes, on failure the toast shows the server's message
 * and the popup stays open. While saving the button is disabled
 * with a "Saving…" label. Escape, the backdrop and the Close
 * button all close the popup, and focus returns to the link.
 */
function initSidebarSettingsPopup(): void {
	const config = adminData();
	if (!config) {
		return;
	}
	const popup = document.getElementById('kk-sidebar-popup');
	if (!popup) {
		return;
	}
	const form = popup.querySelector<HTMLFormElement>('#kk-sidebar-popup-form');
	const closeButton = popup.querySelector<HTMLButtonElement>('button[data-kk-sidebar-popup-close]');
	const submit = form?.querySelector<HTMLButtonElement>('button[type="submit"]') ?? null;
	if (!form || !closeButton || !submit) {
		return;
	}

	let opener: HTMLAnchorElement | null = null;

	const close = (): void => {
		popup.hidden = true;
		if (opener) {
			opener.focus();
			opener = null;
		}
	};

	const open = (link: HTMLAnchorElement): void => {
		opener = link;
		popup.hidden = false;
		closeButton.focus();
	};

	document
		.querySelectorAll<HTMLAnchorElement>('[data-kk-sidebar-settings-popup]')
		.forEach((link) => {
			link.addEventListener('click', (event) => {
				event.preventDefault();
				open(link);
			});
		});

	popup.querySelectorAll('[data-kk-sidebar-popup-close]').forEach((el) => {
		el.addEventListener('click', close);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !popup.hidden) {
			close();
		}
	});

	const originalLabel = submit.textContent;
	form.addEventListener('submit', (event) => {
		event.preventDefault();
		if (submit.disabled) {
			return;
		}
		const checked = Array.from(form.querySelectorAll<HTMLInputElement>('input[type="checkbox"][data-kk-sidebar-id]'))
			.filter((box) => box.checked)
			.map((box) => box.dataset.kkSidebarId ?? '')
			.filter((id) => id !== '');
		submit.disabled = true;
		submit.textContent = adminStrings().saving;
		void postAjax<{ message: string }>(config, 'kk_sidebar_settings_save', { sticky: checked.join(',') })
			.then((data) => {
				showToast(data.message, 'success');
				close();
			})
			.catch((error: unknown) => {
				showToast(errorMessage(error), 'error');
			})
			.finally(() => {
				submit.disabled = false;
				submit.textContent = originalLabel;
			});
	});
}

/**
 * Manual gold sync on Settings -> Gold Prices, with real progress.
 *
 * One AJAX call per enabled source, in run order (the default
 * source first, then the rest — see
 * LogamMuliaApiProvider::enabledSyncSources()): the first call
 * carries no source, each step returns `next` (the following
 * source, or null when done) plus `step`/`total` for the progress
 * bar. While a sync runs the Sync now button is disabled
 * (aria-busy), the progress bar + status line show which source
 * is syncing ("Syncing galeri24… 1/3"), each finished source gets
 * its own line in the step list, and the automatic-sync form is
 * disabled with a note saying it is paused. A per-source failure
 * is reported as its own line and does not stop the remaining
 * sources. At the end the page reloads so the status, history
 * list and chart show the new rows. Without the button (any other
 * admin screen) this does nothing. With no source enabled the
 * button is disabled server-side, and a step that reports no
 * source shows the server's error instead of reloading.
 */
function initGoldSync(): void {
	const button = document.querySelector<HTMLButtonElement>('[data-kk-gold-sync]');
	const result = document.querySelector<HTMLElement>('[data-kk-gold-sync-result]');
	if (!button || !result) {
		return;
	}

	/* Localize data missing (stale page, cached markup, blocked
	 * script): never leave a dead button — a click says so
	 * honestly instead of doing nothing. */
	const config = adminData();
	if (!config) {
		button.addEventListener('click', () => {
			result.textContent = adminStrings().adminDataMissing;
		});
		return;
	}

	const progressWrap = document.querySelector<HTMLElement>('[data-kk-gold-sync-progress]');
	const bar = document.querySelector<HTMLProgressElement>('[data-kk-gold-sync-bar]');
	const statusEl = document.querySelector<HTMLElement>('[data-kk-gold-sync-status]');
	const stepsEl = document.querySelector<HTMLElement>('[data-kk-gold-sync-steps]');
	const settingsForm = document.querySelector<HTMLFormElement>('[data-kk-gold-sync-settings]');
	const runningNote = document.querySelector<HTMLElement>('[data-kk-gold-sync-running]');

	const setRunning = (running: boolean): void => {
		button.disabled = running;
		if (running) {
			button.setAttribute('aria-busy', 'true');
		} else {
			button.removeAttribute('aria-busy');
		}
		if (progressWrap) {
			progressWrap.hidden = !running;
		}
		if (runningNote) {
			runningNote.hidden = !running;
		}
		if (settingsForm) {
			settingsForm.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLButtonElement>('input, select, button').forEach((el) => {
				el.disabled = running;
			});
		}
	};

	button.addEventListener('click', async () => {
		const strings = adminStrings();
		setRunning(true);
		result.textContent = '';
		if (stepsEl) {
			stepsEl.textContent = '';
		}
		if (bar) {
			bar.value = 0;
		}

		let totalInserted = 0;
		let totalSkipped = 0;
		/* null = first call, no source (starts from the first one). */
		let source: string | null = null;

		try {
			/* At most one call per source; the cap is a guard against
			 * a server that ever returns a cycle in `next`. */
			for (let step = 0; step < 10; step += 1) {
				const params: Record<string, string> = source === null ? {} : { source };
				const current = source ?? '';
				/* The step line appears first as "Syncing <source>…"
				 * and is rewritten with its result when the call
				 * returns, so the list always shows live progress. */
				const line = document.createElement('li');
				if (stepsEl) {
					line.textContent = formatTemplate(strings.syncingSource, current);
					stepsEl.appendChild(line);
				}

				const data: GoldSyncStep = await postAjax<GoldSyncStep>(config, 'kk_logam_mulia_sync', params);

				/* No source in the step: either nothing is enabled
				 * at all (total 0 — show the server's honest error
				 * and stop, never reload as if a sync had run), or
				 * the remaining sources were disabled mid-run
				 * (total > 0 — finish with what already synced). */
				if (!data.source) {
					if (data.total === 0) {
						if (data.error) {
							line.textContent = formatTemplate(strings.failed, data.error);
							result.textContent = data.error;
						}
						setRunning(false);
						return;
					}
					break;
				}

				totalInserted += data.inserted;
				totalSkipped += data.skipped;

				const total = typeof data.total === 'number' && data.total > 0 ? data.total : 3;
				const done = typeof data.step === 'number' && data.step > 0 ? data.step : step + 1;
				if (bar) {
					bar.max = total;
					bar.value = done;
				}
				if (statusEl) {
					statusEl.textContent = `${formatTemplate(strings.syncingSource, data.source)} (${done}/${total})`;
				}

				line.textContent = data.error
					? formatTemplate(strings.failed, `${data.source}: ${data.error}`)
					: formatTemplate(strings.syncStepDone, data.source, data.inserted, data.skipped)
						+ (data.latest_date ? formatTemplate(strings.syncStepLatest, data.latest_date) : '');

				if (data.next === null) {
					break;
				}
				source = data.next;
			}

			if (bar) {
				bar.value = bar.max;
			}
			if (statusEl) {
				statusEl.textContent = formatTemplate(strings.syncDone, totalInserted, totalSkipped);
			}
			const summary = document.createElement('div');
			summary.textContent = formatTemplate(strings.syncDone, totalInserted, totalSkipped);
			result.appendChild(summary);
			window.setTimeout(() => window.location.reload(), 1500);
		} catch (error) {
			const line = document.createElement('div');
			line.textContent = errorMessage(error);
			result.appendChild(line);
			setRunning(false);
		}
	});
}

/*
 * Summary tab chart (Gold Prices page): the brand picker + days
 * selector re-render the chart through kk_gold_chart without a
 * page reload. Mirrors the Data Desk preview refresh: debounced,
 * loading state, last-good kept on failure. The brand search
 * filters the checkbox list in place — the list is six brands,
 * so no server round-trip is needed for it. No box checked means
 * the enabled brands (the server falls back the same way), so
 * the chart never goes empty from unchecking alone.
 */
function initGoldSummaryChart(): void {
	const container = document.querySelector<HTMLElement>('[data-kk-gold-summary-chart]');
	const days = document.querySelector<HTMLSelectElement>('[data-kk-gold-summary-days]');
	const search = document.querySelector<HTMLInputElement>('[data-kk-gold-summary-search]');
	const status = document.querySelector<HTMLElement>('[data-kk-gold-summary-status]');
	if (!container || !days) {
		return;
	}

	const config = adminData();
	const boxes = Array.from(document.querySelectorAll<HTMLInputElement>('[data-kk-gold-summary-brand]'));

	const filterBrands = (): void => {
		const query = (search?.value ?? '').trim().toLowerCase();
		for (const box of boxes) {
			const row = box.closest<HTMLElement>('[data-kk-gold-summary-brand-row]');
			if (row) {
				row.hidden = query !== '' && !(row.textContent ?? '').toLowerCase().includes(query);
			}
		}
	};
	search?.addEventListener('input', filterBrands);

	let timer: number | null = null;
	let lastGood = container.innerHTML;
	const refresh = (): void => {
		if (timer !== null) {
			window.clearTimeout(timer);
			timer = null;
		}
		timer = window.setTimeout(() => {
			if (!config) {
				if (status) {
					status.textContent = adminStrings().adminDataMissing;
				}
				return;
			}
			const params: Record<string, string> = {
				days: days.value,
				brands: boxes.filter((box) => box.checked).map((box) => box.value).join(','),
			};
			container.classList.add('is-loading');
			if (status) {
				status.textContent = adminStrings().loadingChart;
			}
			void postAjax<{ html: string }>(config, 'kk_gold_chart', params)
				.then((data) => {
					container.innerHTML = data.html;
					lastGood = data.html;
					if (status) {
						status.textContent = '';
					}
				})
				.catch((error: unknown) => {
					container.innerHTML = lastGood;
					if (status) {
						status.textContent = errorMessage(error);
					}
				})
				.finally(() => {
					container.classList.remove('is-loading');
				});
		}, 300);
	};

	days.addEventListener('change', refresh);
	for (const box of boxes) {
		box.addEventListener('change', refresh);
	}
}

/**
 * Provider picker popups on the Settings -> Markets screen.
 *
 * Each market has a provider dropdown and a "Choose what to show"
 * button. The button opens a centered popup (blurred backdrop) that
 * lists exactly what the selected provider can supply, as checkboxes.
 * Only the selected provider's group is visible and enabled, so only
 * its choices are submitted; switching the provider swaps the group
 * and refreshes the "Showing: ..." summary under the dropdown.
 * The summary also updates live as checkboxes are ticked. Close on
 * Done, backdrop click, or Escape. Without JS the popup stays hidden
 * and the market shows every symbol the provider supplies.
 */
function initProviderPickers(): void {
	const pickers = document.querySelectorAll<HTMLElement>('[data-kk-provider-picker]');

	pickers.forEach((picker) => {
		const market = picker.dataset.kkProviderPicker;
		if (!market) {
			return;
		}

		const select = document.querySelector<HTMLSelectElement>(
			`[data-kk-market-provider="${market}"]`,
		);
		const openButton = document.querySelector<HTMLButtonElement>(
			`[data-kk-provider-picker-open="${market}"]`,
		);
		const summary = document.querySelector<HTMLElement>(
			`[data-kk-provider-picker-summary="${market}"]`,
		);

		if (!select || !openButton) {
			return;
		}

		const groups = Array.from(
			picker.querySelectorAll<HTMLElement>('[data-kk-provider-picker-group]'),
		);

		const showGroup = (providerId: string): void => {
			groups.forEach((group) => {
				const active = group.dataset.kkProviderPickerGroup === providerId;
				group.hidden = !active;
				group.querySelectorAll<HTMLInputElement>('input[type="checkbox"]').forEach((box) => {
					box.disabled = !active;
				});
			});
		};

		const refreshSummary = (): void => {
			if (!summary) {
				return;
			}

			const active = groups.find((group) => !group.hidden);
			if (!active) {
				return;
			}

			const labels = Array.from(
				active.querySelectorAll<HTMLInputElement>('input[type="checkbox"]:checked'),
			).map((box) => {
				const label = box.closest('label');
				return label ? (label.textContent ?? '').trim() : '';
			}).filter((text) => text !== '');

			// The server renders "Showing: ..." with the list; keep
			// the same shape and only swap the list itself.
			const prefix = summary.textContent?.split(':')[0] ?? 'Showing';
			summary.textContent = `${prefix}: ${labels.join(', ')}`;
		};

		const open = (): void => {
			showGroup(select.value);
			picker.hidden = false;
			const done = picker.querySelector<HTMLButtonElement>('[data-kk-provider-picker-close]');
			done?.focus();
		};

		const close = (): void => {
			picker.hidden = true;
			refreshSummary();
			openButton.focus();
		};

		select.addEventListener('change', () => {
			showGroup(select.value);
			refreshSummary();
		});

		openButton.addEventListener('click', open);

		picker.querySelectorAll('[data-kk-provider-picker-close]').forEach((el) => {
			el.addEventListener('click', close);
		});

		picker.addEventListener('change', (event) => {
			if ((event.target as HTMLElement).matches('input[type="checkbox"]')) {
				refreshSummary();
			}
		});

		document.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && !picker.hidden) {
				close();
			}
		});
	});
}

/**
 * Menu item icon picker on Appearance -> Menus.
 *
 * Each menu item has a "Choose icon" button, a preview row (icon
 * square + colour picker, same size) and the icon name under it. The
 * button opens one shared centered popup (blurred backdrop) with a
 * search box and a grid of the theme's Feather icons (290). Picking
 * an icon writes it to the item's hidden input and refreshes the
 * preview and the name; "Remove icon" clears it. The colour picker
 * writes to the item's hidden colour input, colours the preview live
 * and appends the hex to the name; the × clears back to the inherited
 * link colour. Close on Close, backdrop click, or Escape. The grid is
 * built lazily on first open. Without JS the button does nothing and
 * the saved icon (if any) stays.
 */
function initMenuIconPickers(): void {
	const popup = document.getElementById('kk-menu-icon-popup');
	if (!popup) {
		return;
	}
	const data =
		typeof kkAdminData !== 'undefined' ? kkAdminData.menuIcons : undefined;
	if (!data) {
		return;
	}
	const grid = popup.querySelector<HTMLElement>('[data-kk-menu-icon-grid]');
	const search =
		popup.querySelector<HTMLInputElement>('#kk-menu-icon-search');
	const empty = popup.querySelector<HTMLElement>('[data-kk-menu-icon-empty]');
	if (!grid || !search || !empty) {
		return;
	}

	let currentItemId: string | null = null;
	let opener: HTMLButtonElement | null = null;
	let gridBuilt = false;

	const iconSvg = (name: string): string => data.builtIn[name] ?? '';

	const buildGrid = (): void => {
		if (gridBuilt) {
			return;
		}
		gridBuilt = true;
		const fragment = document.createDocumentFragment();
		const addButton = (name: string): void => {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'kk-menu-icon-grid-item';
			button.dataset.kkMenuIconName = name;
			button.title = name;
			button.setAttribute('aria-label', name);
			button.innerHTML = iconSvg(name);
			fragment.appendChild(button);
		};
		Object.keys(data.builtIn)
			.sort()
			.forEach(addButton);
		grid.appendChild(fragment);
	};

	const markSelected = (name: string): void => {
		grid
			.querySelectorAll('.kk-menu-icon-grid-item.is-selected')
			.forEach((el) => el.classList.remove('is-selected'));
		if (name === '') {
			return;
		}
		grid
			.querySelector(`[data-kk-menu-icon-name="${CSS.escape(name)}"]`)
			?.classList.add('is-selected');
	};

	const filterGrid = (): void => {
		const query = search.value.trim().toLowerCase();
		let visible = 0;
		grid
			.querySelectorAll<HTMLElement>('.kk-menu-icon-grid-item')
			.forEach((button) => {
				const name = button.dataset.kkMenuIconName ?? '';
				const match = query === '' || name.toLowerCase().includes(query);
				button.hidden = !match;
				if (match) {
					visible += 1;
				}
			});
		empty.hidden = visible > 0;
	};

	const colorInputFor = (itemId: string): HTMLInputElement | null =>
		document.querySelector<HTMLInputElement>(
			`[data-kk-menu-icon-color-input="${CSS.escape(itemId)}"]`,
		);

	const setName = (itemId: string, name: string): void => {
		const nameEl = document.querySelector<HTMLElement>(
			`[data-kk-menu-icon-name="${CSS.escape(itemId)}"]`,
		);
		if (!nameEl) {
			return;
		}
		if (name === '') {
			nameEl.textContent = data.noIcon;
			return;
		}
		const color = colorInputFor(itemId)?.value ?? '';
		nameEl.textContent = color === '' ? name : `${name} · ${color}`;
	};

	const applyColor = (itemId: string, color: string): void => {
		const hidden = colorInputFor(itemId);
		if (hidden) {
			hidden.value = color;
		}
		const preview = document.querySelector<HTMLElement>(
			`[data-kk-menu-icon-preview="${CSS.escape(itemId)}"]`,
		);
		if (preview) {
			if (color === '') {
				preview.style.removeProperty('color');
			} else {
				preview.style.color = color;
			}
		}
		const clear = document.querySelector<HTMLElement>(
			`[data-kk-menu-icon-color-clear="${CSS.escape(itemId)}"]`,
		);
		if (clear) {
			clear.hidden = color === '';
		}
		// The swatch shows the transparency checkerboard while no colour
		// is saved; the native input keeps the default green as the
		// dialog's starting value (it is never the saved state).
		const wrap = document.querySelector<HTMLElement>(
			`[data-kk-menu-icon-color-wrap="${CSS.escape(itemId)}"]`,
		);
		if (wrap) {
			wrap.classList.toggle('is-empty', color === '');
		}
		const input = document.querySelector<HTMLInputElement>(
			`[data-kk-menu-icon-input="${CSS.escape(itemId)}"]`,
		);
		setName(itemId, input?.value ?? '');
	};

	const setPreview = (itemId: string, name: string): void => {
		const input = document.querySelector<HTMLInputElement>(
			`[data-kk-menu-icon-input="${CSS.escape(itemId)}"]`,
		);
		if (input) {
			input.value = name;
		}
		const preview = document.querySelector<HTMLElement>(
			`[data-kk-menu-icon-preview="${CSS.escape(itemId)}"]`,
		);
		if (!preview) {
			return;
		}
		preview.textContent = '';
		preview.classList.toggle('is-empty', name === '');
		if (name !== '') {
			const iconHolder = document.createElement('span');
			iconHolder.innerHTML = iconSvg(name);
			preview.append(iconHolder);
		}
		setName(itemId, name);
	};

	const close = (): void => {
		popup.hidden = true;
		currentItemId = null;
		if (opener) {
			opener.focus();
			opener = null;
		}
	};

	const open = (button: HTMLButtonElement): void => {
		/* Menu buttons carry the item id; widget buttons carry the
		 * widget field id — both are the key the shared setPreview /
		 * setName below look the hidden input up by. */
		currentItemId =
			button.dataset.kkMenuIconPick ??
			button.dataset.kkWidgetIconPick ??
			null;
		if (!currentItemId) {
			return;
		}
		opener = button;
		buildGrid();
		const input = document.querySelector<HTMLInputElement>(
			`[data-kk-menu-icon-input="${CSS.escape(currentItemId)}"]`,
		);
		markSelected(input?.value ?? '');
		search.value = '';
		filterGrid();
		popup.hidden = false;
		search.focus();
	};

	document
		.querySelectorAll<HTMLButtonElement>('[data-kk-menu-icon-pick]')
		.forEach((button) => {
			button.addEventListener('click', () => open(button));
		});

	/* Widget icon buttons (Agenda widget header icon) are re-rendered
	 * by the Customizer, so load-time binding would miss them: they
	 * are wired by delegation instead, into the same open() above. */
	document.addEventListener('click', (event) => {
		const button = (event.target as HTMLElement).closest(
			'[data-kk-widget-icon-pick]',
		) as HTMLButtonElement | null;
		if (!button) {
			return;
		}
		open(button);
	});

	document
		.querySelectorAll<HTMLInputElement>('[data-kk-menu-icon-color]')
		.forEach((picker) => {
			picker.addEventListener('input', () => {
				const itemId = picker.dataset.kkMenuIconColor ?? '';
				if (itemId !== '') {
					applyColor(itemId, picker.value);
				}
			});
		});

	document
		.querySelectorAll<HTMLButtonElement>('[data-kk-menu-icon-color-clear]')
		.forEach((button) => {
			button.addEventListener('click', () => {
				const itemId = button.dataset.kkMenuIconColorClear ?? '';
				if (itemId === '') {
					return;
				}
				const picker = document.querySelector<HTMLInputElement>(
					`[data-kk-menu-icon-color="${CSS.escape(itemId)}"]`,
				);
				if (picker) {
					picker.value = data.defaultColor;
				}
				applyColor(itemId, '');
			});
		});

	grid.addEventListener('click', (event) => {
		const button = (event.target as HTMLElement).closest(
			'.kk-menu-icon-grid-item',
		) as HTMLButtonElement | null;
		if (!button || !currentItemId) {
			return;
		}
		const name = button.dataset.kkMenuIconName ?? '';
		setPreview(currentItemId, name);
		markSelected(name);
		close();
	});

	search.addEventListener('input', filterGrid);

	popup
		.querySelector('[data-kk-menu-icon-remove]')
		?.addEventListener('click', () => {
			if (currentItemId) {
				setPreview(currentItemId, '');
			}
			close();
		});

	popup.querySelectorAll('[data-kk-menu-icon-close]').forEach((el) => {
		el.addEventListener('click', close);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !popup.hidden) {
			close();
		}
	});
}

interface AgendaLinkResult {
	id: number;
	title: string;
	type: string;
	url: string;
}

/**
 * Agenda widget form extras (widgets.php + Customizer).
 *
 * - "Show icon" toggles the icon picker row in the same form.
 * - "Link to" searches published posts and pages by title through
 *   kk_agenda_link_search; picking a result stores its id and shows
 *   the chosen title, "Remove link" clears it again. The search box
 *   is progressive enhancement: without JS the saved link (if any)
 *   stays exactly as the server rendered it.
 *
 * Widget forms are re-rendered by the Customizer, so everything here
 * is delegated from the document — no load-time binding.
 */
function initAgendaWidgetFields(): void {
	const config = adminData();
	if (!config) {
		return;
	}

	/* "Card style" shows / hides the Choose Own colour rows in the
	 * same form. */
	document.addEventListener('change', (event) => {
		const select = (event.target as HTMLElement).closest(
			'[data-kk-agenda-style-select]',
		) as HTMLSelectElement | null;
		if (!select) {
			return;
		}
		const field = scopeFor(select)?.querySelector<HTMLElement>(
			'[data-kk-agenda-custom-colors]',
		);
		if (field) {
			field.hidden = select.value !== 'custom';
		}
	});

	/* A Choose Own colour row: the visible swatch is only the picker —
	 * the hidden input holds the real value (empty = fall back to the
	 * theme colour), and the × button clears it back to empty. */
	const syncColorRow = (scope: HTMLElement | null, key: string): void => {
		const input = scope?.querySelector<HTMLInputElement>(
			`[data-kk-agenda-color-input="${key}"]`,
		);
		const hex = scope?.querySelector<HTMLElement>(
			`[data-kk-agenda-color-hex="${key}"]`,
		);
		const wrap = scope?.querySelector<HTMLElement>(
			`[data-kk-agenda-color-wrap="${key}"]`,
		);
		const clear = scope?.querySelector<HTMLElement>(
			`[data-kk-agenda-color-clear="${key}"]`,
		);
		if (hex && input) {
			hex.textContent = input.value;
		}
		if (wrap) {
			wrap.classList.toggle('is-empty', !input || input.value === '');
		}
		if (clear) {
			clear.hidden = !input || input.value === '';
		}
	};

	document.addEventListener('input', (event) => {
		const picker = (event.target as HTMLElement).closest(
			'[data-kk-agenda-color]',
		) as HTMLInputElement | null;
		if (!picker) {
			return;
		}
		const key = picker.dataset.kkAgendaColor ?? '';
		const scope = scopeFor(picker);
		const input = scope?.querySelector<HTMLInputElement>(
			`[data-kk-agenda-color-input="${key}"]`,
		);
		if (input) {
			input.value = picker.value;
		}
		syncColorRow(scope, key);
	});

	document.addEventListener('click', (event) => {
		const clear = (event.target as HTMLElement).closest(
			'[data-kk-agenda-color-clear]',
		) as HTMLElement | null;
		if (!clear) {
			return;
		}
		const key = clear.dataset.kkAgendaColorClear ?? '';
		const scope = scopeFor(clear);
		const input = scope?.querySelector<HTMLInputElement>(
			`[data-kk-agenda-color-input="${key}"]`,
		);
		if (input) {
			input.value = '';
		}
		syncColorRow(scope, key);
	});

	const scopeFor = (el: HTMLElement): HTMLElement | null =>
		el.closest('form') ??
		el.closest('.widget-content') ??
		el.parentElement;

	const resultsFor = (input: HTMLInputElement): HTMLElement | null =>
		scopeFor(input)?.querySelector<HTMLElement>(
			'[data-kk-agenda-link-results]',
		) ?? null;

	const setMessage = (results: HTMLElement, message: string): void => {
		results.replaceChildren();
		const line = document.createElement('p');
		line.className = 'description';
		line.textContent = message;
		results.append(line);
		results.hidden = false;
	};

	const renderResults = (
		results: HTMLElement,
		items: AgendaLinkResult[],
	): void => {
		results.replaceChildren();
		if (items.length === 0) {
			setMessage(results, adminStrings().noResults);
			return;
		}
		const fragment = document.createDocumentFragment();
		for (const item of items) {
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'kk-term-picker-option';
			button.dataset.kkAgendaLinkPick = String(item.id);
			button.dataset.kkAgendaLinkTitle = item.title;
			button.dataset.kkAgendaLinkType = item.type;
			button.textContent = `${item.title} — ${item.type}`;
			fragment.appendChild(button);
		}
		results.append(fragment);
		results.hidden = false;
	};

	/* One debounced request per input; a newer keystroke aborts the
	 * previous one, so a slow reply can never overwrite fresher
	 * results. */
	const timers = new WeakMap<HTMLElement, number>();
	const controllers = new WeakMap<HTMLElement, AbortController>();

	const runSearch = async (
		input: HTMLInputElement,
		query: string,
	): Promise<void> => {
		const results = resultsFor(input);
		if (!results) {
			return;
		}
		const controller = new AbortController();
		controllers.set(input, controller);
		try {
			const data = await postAjax<{ results: AgendaLinkResult[] }>(
				config,
				'kk_agenda_link_search',
				{ query },
				controller.signal,
			);
			/* Typed on meanwhile: a newer search owns the list. */
			if (input.value.trim() !== query) {
				return;
			}
			renderResults(results, data.results);
		} catch (error) {
			if (error instanceof DOMException && error.name === 'AbortError') {
				return;
			}
			if (input.value.trim() !== query) {
				return;
			}
			setMessage(results, errorMessage(error));
		}
	};

	document.addEventListener('input', (event) => {
		const input = (event.target as HTMLElement).closest(
			'[data-kk-agenda-link-search]',
		) as HTMLInputElement | null;
		if (!input) {
			return;
		}
		const query = input.value.trim();
		const results = resultsFor(input);
		if (!results) {
			return;
		}
		const prev = timers.get(input);
		if (prev !== undefined) {
			window.clearTimeout(prev);
		}
		controllers.get(input)?.abort();
		if (query.length < 2) {
			results.replaceChildren();
			results.hidden = true;
			return;
		}
		setMessage(results, adminStrings().searching);
		timers.set(
			input,
			window.setTimeout(() => {
				void runSearch(input, query);
			}, 250),
		);
	});

	/* Pick a result: store its id, show the chosen title + type. */
	document.addEventListener('click', (event) => {
		const button = (event.target as HTMLElement).closest(
			'[data-kk-agenda-link-pick]',
		) as HTMLButtonElement | null;
		if (!button) {
			return;
		}
		const scope = scopeFor(button);
		if (!scope) {
			return;
		}
		const input = scope.querySelector<HTMLInputElement>(
			'[data-kk-agenda-link-input]',
		);
		const search = scope.querySelector<HTMLInputElement>(
			'[data-kk-agenda-link-search]',
		);
		const chosen = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-chosen]',
		);
		const titleEl = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-chosen-title]',
		);
		const typeEl = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-chosen-type]',
		);
		const results = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-results]',
		);
		if (input) {
			input.value = button.dataset.kkAgendaLinkPick ?? '0';
		}
		if (titleEl) {
			titleEl.textContent = button.dataset.kkAgendaLinkTitle ?? '';
		}
		if (typeEl) {
			typeEl.textContent = button.dataset.kkAgendaLinkType ?? '';
		}
		if (chosen) {
			chosen.hidden = false;
		}
		if (results) {
			results.replaceChildren();
			results.hidden = true;
		}
		if (search) {
			search.value = '';
		}
		scope
			.querySelector<HTMLButtonElement>('[data-kk-agenda-link-clear]')
			?.focus();
	});

	/* "Remove link": clear the stored id and the chosen row. */
	document.addEventListener('click', (event) => {
		const button = (event.target as HTMLElement).closest(
			'[data-kk-agenda-link-clear]',
		) as HTMLButtonElement | null;
		if (!button) {
			return;
		}
		const scope = scopeFor(button);
		if (!scope) {
			return;
		}
		const input = scope.querySelector<HTMLInputElement>(
			'[data-kk-agenda-link-input]',
		);
		const search = scope.querySelector<HTMLInputElement>(
			'[data-kk-agenda-link-search]',
		);
		const chosen = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-chosen]',
		);
		const results = scope.querySelector<HTMLElement>(
			'[data-kk-agenda-link-results]',
		);
		if (input) {
			input.value = '0';
		}
		if (chosen) {
			chosen.hidden = true;
		}
		if (results) {
			results.replaceChildren();
			results.hidden = true;
		}
		if (search) {
			search.value = '';
			search.focus();
		}
	});

	/* Enter picks the first result instead of submitting the form. */
	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Enter') {
			return;
		}
		const input = (event.target as HTMLElement).closest(
			'[data-kk-agenda-link-search]',
		) as HTMLInputElement | null;
		if (!input) {
			return;
		}
		event.preventDefault();
		scopeFor(input)
			?.querySelector<HTMLButtonElement>('[data-kk-agenda-link-pick]')
			?.click();
	});
}

/**
 * Select2-style term pickers for widget forms.
 *
 * A `<select data-kk-term-picker>` (rendered by the
 * widget form with every term as an option) becomes a searchable
 * picker: a search box filters the option list, picked terms show
 * as removable chips, and the underlying select stays in sync so
 * the widget form submits the picked term IDs. A multiple select
 * picks many; a single select picks one (its empty option is the
 * "none" state). The select keeps working without JS (a plain
 * multi-select), so the picker is progressive enhancement, not a
 * requirement.
 *
 * Widget forms are added dynamically (Customizer, Legacy Widget
 * block), so a MutationObserver picks up selects that appear
 * after the first pass.
 */
function initTermPickers(): void {
	/* Same browse cap as the block editor's TermSearchField. */
	const BROWSE_FULL_MAX = 50;
	const BROWSE_LIMIT = 10;

	const initOne = (select: HTMLSelectElement): void => {
		if (select.dataset.kkPickerInit === '1') {
			return;
		}
		select.dataset.kkPickerInit = '1';

		const single = !select.multiple;
		const emptyValue =
			Array.from(select.options).find((option) => option.value === '' || option.value === '0')?.value ?? '';

		const wrapper = document.createElement('div');
		wrapper.className = 'kk-term-picker';
		select.after(wrapper);
		// Hide the native select but keep it in the form so its
		// selected options submit with the widget.
		select.style.display = 'none';

		const chips = document.createElement('div');
		chips.className = 'kk-term-picker-chips';
		const search = document.createElement('input');
		search.type = 'search';
		search.className = 'kk-term-picker-search widefat';
		search.placeholder = 'Search…';
		search.setAttribute('aria-label', 'Search terms');
		const list = document.createElement('div');
		list.className = 'kk-term-picker-list';
		wrapper.append(chips, search, list);

		/* The option list is a dropdown, not a permanently open
		 * list: collapsed until the search is used, so a picker in
		 * a crowded form (the Data Desk top bar) stays compact.
		 * Focus or click on the search opens it; Escape closes it;
		 * a click outside closes it. Focus moving inside the
		 * wrapper (chips, options) keeps it open, so picking with
		 * the mouse or keyboard never collapses the list
		 * mid-pick — a null relatedTarget (e.g. a Safari click)
		 * leaves it alone and the outside-click handler decides. */
		const setOpen = (open: boolean): void => {
			wrapper.classList.toggle('is-open', open);
		};
		search.addEventListener('focus', () => {
			setOpen(true);
		});
		search.addEventListener('click', () => {
			setOpen(true);
		});
		search.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				setOpen(false);
				search.blur();
			}
		});
		wrapper.addEventListener('focusout', (event) => {
			const next = event.relatedTarget as Node | null;
			if (next && !wrapper.contains(next)) {
				setOpen(false);
			}
		});
		document.addEventListener('pointerdown', (event) => {
			if (!wrapper.contains(event.target as Node)) {
				setOpen(false);
			}
		});

		/* Read once, reused for every render. */
		const options = Array.from(select.options)
			.filter((option) => option.value !== '' && option.value !== '0')
			.map((option) => ({
				value: option.value,
				label: option.text,
			}));

		const render = (): void => {
			const query = search.value.trim().toLowerCase();
			const selected = new Set(
				Array.from(select.selectedOptions).map((o) => o.value),
			);
			chips.innerHTML = '';
			for (const option of options) {
				if (!selected.has(option.value)) {
					continue;
				}
				const chip = document.createElement('button');
				chip.type = 'button';
				chip.className = 'kk-term-picker-chip';
				chip.textContent = option.label + ' ×';
				chip.addEventListener('click', () => {
					setSelected(option.value, false);
				});
				chips.append(chip);
			}
			const matching = options.filter(
				(option) =>
					!selected.has(option.value) &&
					(query === '' || option.label.toLowerCase().includes(query)),
			);
			const capped = query === '' && options.length >= BROWSE_FULL_MAX;
			const shown = capped ? matching.slice(0, BROWSE_LIMIT) : matching;
			list.innerHTML = '';
			for (const option of shown) {
				const row = document.createElement('button');
				row.type = 'button';
				row.className = 'kk-term-picker-option';
				row.textContent = option.label;
				row.addEventListener('click', () => {
					setSelected(option.value, true);
					search.value = '';
					if (!single) {
						search.focus();
					}
					render();
				});
				list.append(row);
			}
			if (capped && shown.length > 0) {
				const count = document.createElement('p');
				count.className = 'description kk-term-picker-count';
				count.textContent = formatTemplate(
					adminStrings().showingOf,
					shown.length,
					options.length,
				);
				list.append(count);
			}
			if (list.childElementCount === 0) {
				const empty = document.createElement('p');
				empty.className = 'description';
				empty.textContent = query === '' ? 'No terms.' : 'No matching terms.';
				list.append(empty);
			}
		};

		const setSelected = (value: string, next: boolean): void => {
			if (single) {
				select.value = next ? value : emptyValue;
			} else {
				for (const option of Array.from(select.options)) {
					if (option.value === value) {
						option.selected = next;
					}
				}
			}
			/* The choice is made; nothing left to pick. */
			if (single && next) {
				setOpen(false);
			}
			render();
		};

		search.addEventListener('input', render);
		render();
	};

	const scan = (root: ParentNode): void => {
		root
			.querySelectorAll<HTMLSelectElement>('select[data-kk-term-picker]')
			.forEach(initOne);
	};
	scan(document);

	const observer = new MutationObserver((mutations) => {
		for (const mutation of Array.from(mutations)) {
			for (const node of Array.from(mutation.addedNodes)) {
				if (node instanceof HTMLElement) {
					scan(node);
				}
			}
		}
	});
	observer.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initAdmin);
} else {
	initAdmin();
}
