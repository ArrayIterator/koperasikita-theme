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
 */

interface AdminData {
	ajaxUrl: string;
	nonce: string;
}

declare const kkAdminData: AdminData | undefined;

interface AjaxResponse {
	success?: boolean;
	data?: {
		cssUrl?: string;
		status?: string;
		message?: string;
	};
}

function adminData(): AdminData | null {
	if (typeof kkAdminData === 'undefined' || !kkAdminData) {
		return null;
	}
	if (!kkAdminData.ajaxUrl || !kkAdminData.nonce) {
		return null;
	}
	return kkAdminData;
}

async function postAjax(action: string, params: Record<string, string>): Promise<AjaxResponse> {
	const data = adminData();
	if (!data) {
		throw new Error('Admin data is not available.');
	}
	const body = new URLSearchParams({ action, nonce: data.nonce, ...params });
	const response = await fetch(data.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	});
	if (!response.ok) {
		throw new Error(`Request failed (${response.status}).`);
	}
	const json = (await response.json()) as AjaxResponse;
	if (!json.success) {
		throw new Error(json.data?.message ?? 'Request failed.');
	}
	return json;
}

function errorMessage(error: unknown): string {
	return error instanceof Error && error.message !== '' ? error.message : 'Download failed.';
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

async function downloadFonts(
	downloadButton: HTMLButtonElement,
	clearButton: HTMLButtonElement,
	ids: string[],
	rows: HTMLLIElement[],
): Promise<void> {
	setButtonsDisabled(downloadButton, clearButton, true);
	try {
		for (let i = 0; i < ids.length; i += 1) {
			const id = ids[i] as string;
			const row = rows[i];
			try {
				await postAjax('kk_download_font', { font: id });
				if (row) {
					setRowStatus(row, 'Ready');
				}
			} catch (error) {
				if (row) {
					setRowStatus(row, `Failed: ${errorMessage(error)}`);
				}
			}
		}
	} finally {
		setButtonsDisabled(downloadButton, clearButton, false);
	}
}

async function clearFonts(
	downloadButton: HTMLButtonElement,
	clearButton: HTMLButtonElement,
	rows: HTMLLIElement[],
): Promise<void> {
	setButtonsDisabled(downloadButton, clearButton, true);
	try {
		await postAjax('kk_clear_fonts', {});
		rows.forEach((row) => setRowStatus(row, 'Not downloaded yet'));
	} catch (error) {
		console.warn('Could not clear the downloaded fonts.', error);
	} finally {
		setButtonsDisabled(downloadButton, clearButton, false);
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

	const downloadButton = container.querySelector<HTMLButtonElement>('[data-kk-font-action="download"]');
	const clearButton = container.querySelector<HTMLButtonElement>('[data-kk-font-action="clear"]');
	if (!downloadButton || !clearButton) {
		return;
	}

	const ids = (downloadButton.dataset.kkFonts ?? '').split(/\s+/).filter((id) => id !== '');
	const rows = Array.from(container.querySelectorAll('li'));

	/* Nothing to download when no Google font is selected. */
	if (ids.length === 0) {
		downloadButton.disabled = true;
	}

	downloadButton.addEventListener('click', () => {
		void downloadFonts(downloadButton, clearButton, ids, rows);
	});
	clearButton.addEventListener('click', () => {
		void clearFonts(downloadButton, clearButton, rows);
	});
}

if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', initAdmin);
} else {
	initAdmin();
}
