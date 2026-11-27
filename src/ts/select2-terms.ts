/**
 * Real Select2 term pickers for classic widget forms.
 *
 * A `<select data-kk-select2-terms>` (rendered by the widget form
 * with only its already-picked terms as options) becomes a Select2
 * with AJAX search: the dropdown stays closed until the field is
 * focused or clicked, typing searches the taxonomy through
 * kk_search_terms, and the underlying select stays in sync so the
 * widget form submits the picked term IDs. The mode follows the
 * field itself: a `multiple` select gets a multi Select2, a plain
 * select gets a single Select2 with a placeholder and a clear
 * button - nothing about the mode is hardcoded here.
 *
 * Select2 is a jQuery plugin, so it is wired to the jQuery
 * WordPress already loads in the admin (see the
 * `jquery-global-shim` in vite.config.ts): the factory call in
 * initSelect2TermPickers() attaches it to `window.jQuery`, and
 * the Indonesian strings load from the package's own translation
 * file before the first picker initializes. Widget forms are
 * added dynamically (Customizer, Legacy Widget block), so a
 * MutationObserver picks up selects that appear after the first
 * pass.
 */
import 'select2/dist/css/select2.css';
import select2Factory from 'select2';

declare global {
	interface Window {
		jQuery?: JQueryStatic;
	}
}

type Select2Result = { id: number; text: string };
type Select2AjaxResponse = {
	success: boolean;
	data: {
		results: Select2Result[];
		pagination: { more: boolean };
	} | null;
};

export interface Select2TermPickerData {
	ajaxUrl: string;
	nonce: string;
}

/* Attach Select2 to the admin's jQuery; a no-op when another
 * plugin already attached it or jQuery is missing. */
function attachSelect2(): boolean {
	const jq = window.jQuery;
	if (!jq) {
		return false;
	}
	if (typeof jq.fn.select2 === 'function') {
		return true;
	}
	if (typeof select2Factory !== 'function') {
		return false;
	}
	try {
		select2Factory(window, jq);
	} catch {
		return false;
	}
	return typeof jq.fn.select2 === 'function';
}

/* The Indonesian strings register with Select2's AMD loader, so
 * they must load after the factory ran - a static import would
 * execute first and silently no-op. */
async function loadIndonesianStrings(): Promise<void> {
	try {
		// @ts-expect-error TS7016: the translation ships as an untyped UMD side-effect script.
		await import('select2/dist/js/i18n/id.js');
	} catch {
		/* English strings stay: the picker still works. */
	}
}

export function initSelect2TermPickers(data: Select2TermPickerData): void {
	const jq = window.jQuery;
	if (!attachSelect2() || !jq || typeof jq.fn.select2 !== 'function') {
		return;
	}
	if (!data.ajaxUrl || !data.nonce) {
		return;
	}

	const initOne = (select: HTMLSelectElement): void => {
		if (select.dataset.kkSelect2Init === '1') {
			return;
		}
		select.dataset.kkSelect2Init = '1';

		/* The mode follows the field: multiple select, multi
		 * Select2; plain select, single Select2. */
		const multiple = select.multiple;
		const taxonomy = select.dataset.taxonomy ?? '';
		const placeholder = select.dataset.placeholder ?? '';
		const $select = jq(select);
		$select.select2({
			width: '100%',
			language: 'id',
			placeholder: placeholder === '' ? undefined : placeholder,
			allowClear: !multiple,
			dropdownParent: $select.parent(),
			minimumInputLength: 0,
			/* The endpoint reads the nonce from $_POST (see
			 * Ajax::dispatch), so the search must POST - Select2's
			 * default is GET. */
			ajax: {
				url: data.ajaxUrl,
				type: 'POST',
				dataType: 'json',
				delay: 250,
				cache: true,
				data: (params: Select2.QueryOptions): Record<string, unknown> => ({
					action: 'kk_search_terms',
					nonce: data.nonce,
					q: params.term ?? '',
					taxonomy,
					page: params.page ?? 1,
				}),
				processResults: (response: unknown): Select2.ProcessedResult => {
					const envelope = response as Select2AjaxResponse;
					return {
						results: envelope?.data?.results ?? [],
						pagination: envelope?.data?.pagination ?? { more: false },
					};
				},
			},
		});
	};

	const scan = (root: ParentNode): void => {
		root.querySelectorAll<HTMLSelectElement>('select[data-kk-select2-terms]').forEach(initOne);
	};

	/* The translation registers before any picker opens, so the
	 * dropdown strings are Indonesian from the first open. */
	void loadIndonesianStrings().then(() => {
		scan(document);

		const observer = new MutationObserver((mutations) => {
			for (const mutation of mutations) {
				for (const node of Array.from(mutation.addedNodes)) {
					if (node instanceof HTMLElement) {
						scan(node);
					}
				}
			}
		});
		observer.observe(document.body, { childList: true, subtree: true });
	});
}
