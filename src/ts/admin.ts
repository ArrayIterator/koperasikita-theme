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
	editKickerHint: string;
	editTitleHint: string;
	previewFailed: string;
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
	syncingSource: 'Syncing %s…',
	syncStepDone: '%s: inserted %s, skipped %s',
	syncStepLatest: ' (latest %s)',
	syncDone: 'Done — inserted %s, skipped %s (already in database). Reloading…',
	loadingPreview: 'Loading preview…',
	editKickerHint: 'Click to edit the kicker.',
	editTitleHint: 'Click to edit the title.',
	previewFailed: 'Could not load the preview.',
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
		editKickerHint: strings?.editKickerHint ?? FALLBACK_STRINGS.editKickerHint,
		editTitleHint: strings?.editTitleHint ?? FALLBACK_STRINGS.editTitleHint,
		previewFailed: strings?.previewFailed ?? FALLBACK_STRINGS.previewFailed,
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
		initGoldSync,
		initDataDeskPreview,
		initDataDeskWidgetPreview,
		initDataDeskPopupPreview,
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
 * brands, palette, title, kicker — re-renders it through
 * kk_data_desk_preview, debounced so typing does not fire a request
 * per keystroke. The brands picker is a chip UI over the hidden
 * select and never fires `change` on it, so clicks in its wrapper
 * refresh too. The palette is a segmented control of radios synced
 * into the hidden #kk-template-palette input. A failed refresh keeps
 * the last good preview and reports the error under the box.
 *
 * The preview kicker and title are edited inline: neither has a
 * visible form row (both are hidden inputs) — the preview IS the
 * editor. Clicking either text edits it in place; Enter or blur
 * commits into the hidden field and refreshes the preview, Escape
 * cancels and restores the text. The container carries
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
	if (!kind || !symbol || !days || !brands || !palette || !title || !kicker) {
		return;
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
				brands: Array.from(brands.selectedOptions)
					.map((option) => option.value)
					.join(','),
			};
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

	/* Inline editing of the preview kicker + title, delegated on the
	 * container because every refresh replaces the preview DOM.
	 * Click to edit (text selected), Enter or blur commits the text
	 * into the hidden field and refreshes the preview, Escape
	 * cancels and restores the previous text. Clearing the title
	 * means empty (automatic title); clearing the kicker means
	 * empty (default 'Meja Data'). */
	let editing: HTMLElement | null = null;
	let editingOriginal = '';

	const finishEdit = (commit: boolean): void => {
		if (!editing) {
			return;
		}
		const el = editing;
		editing = null;
		const field = el.classList.contains('kk-data-desk-kicker') ? kicker : title;
		if (commit) {
			field.value = el.textContent?.trim() ?? '';
		} else {
			el.textContent = editingOriginal;
		}
		el.removeAttribute('contenteditable');
		el.classList.remove('is-editing');
		el.blur();
		if (commit) {
			refresh();
		}
	};

	container.addEventListener('click', (event) => {
		const target = (event.target as HTMLElement | null)?.closest?.(
			'.kk-data-desk-kicker, .kk-data-desk-title',
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
	[days, title, kicker].forEach((el) => {
		el.addEventListener('input', refresh);
	});
	brands.addEventListener('change', refresh);
	brands.parentElement?.querySelector('.kk-term-picker')?.addEventListener('click', refresh);
	markEditable();
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
		currentItemId = button.dataset.kkMenuIconPick ?? null;
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

/**
 * Select2-style term pickers for widget forms.
 *
 * A `<select multiple data-kk-term-picker>` (rendered by the
 * widget form with every term as an option) becomes a searchable
 * picker: a search box filters the option list, picked terms show
 * as removable chips, and the underlying select stays in sync so
 * the widget form submits the picked term IDs. The select keeps
 * working without JS (a plain multi-select), so the picker is
 * progressive enhancement, not a requirement.
 *
 * Widget forms are added dynamically (Customizer, Legacy Widget
 * block), so a MutationObserver picks up selects that appear
 * after the first pass.
 */
function initTermPickers(): void {
	const initOne = (select: HTMLSelectElement): void => {
		if (select.dataset.kkPickerInit === '1') {
			return;
		}
		select.dataset.kkPickerInit = '1';

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

		const options = Array.from(select.options).map((option) => ({
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
			list.innerHTML = '';
			for (const option of options) {
				if (selected.has(option.value)) {
					continue;
				}
				if (query !== '' && !option.label.toLowerCase().includes(query)) {
					continue;
				}
				const row = document.createElement('button');
				row.type = 'button';
				row.className = 'kk-term-picker-option';
				row.textContent = option.label;
				row.addEventListener('click', () => {
					setSelected(option.value, true);
					search.value = '';
					search.focus();
					render();
				});
				list.append(row);
			}
			if (list.childElementCount === 0) {
				const empty = document.createElement('p');
				empty.className = 'description';
				empty.textContent = query === '' ? 'No terms.' : 'No matching terms.';
				list.append(empty);
			}
		};

		const setSelected = (value: string, next: boolean): void => {
			for (const option of Array.from(select.options)) {
				if (option.value === value) {
					option.selected = next;
				}
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
