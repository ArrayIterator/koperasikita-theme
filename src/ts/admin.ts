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

interface AdminStrings {
	ready: string;
	failed: string;
	notDownloaded: string;
	downloadFailed: string;
	adminDataMissing: string;
}

interface AdminData {
	ajaxUrl: string;
	nonce: string;
	strings?: Partial<AdminStrings>;
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
	};
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

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initAdmin);
} else {
	initAdmin();
}
