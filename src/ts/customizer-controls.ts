/**
 * Koperasi Kita — Customizer controls pane script.
 *
 * Powers the Select2-style term pickers in AJAX mode (no jQuery):
 * TermSelectControl (single) and TermMultiSelectControl (multi).
 * Neither control renders its option list into the page; both ask
 * `TermSearch` (admin-ajax, `kk_search_terms`) for the matches as
 * the editor types (debounced, capped at 20 rows), so a site with
 * hundreds of categories or tags stays light on both the server and
 * the client. The pick syncs into the hidden, setting-linked input
 * (one term ID for the single select, a JSON array for the multi),
 * so the Customizer picks the value up as one setting value. Runs
 * only in the Customizer controls pane, never on the frontend.
 */

export {};

declare const kkCustomizerControls:
	| { ajaxUrl: string; nonce: string }
	| undefined;

interface TermResult {
	id: number;
	text: string;
}

function ajaxConfig(): { ajaxUrl: string; nonce: string } | null {
	if (
		typeof kkCustomizerControls === 'undefined' ||
		!kkCustomizerControls.ajaxUrl ||
		!kkCustomizerControls.nonce
	) {
		return null;
	}
	return kkCustomizerControls;
}

async function searchTerms(
	taxonomy: string,
	query: string,
	signal: AbortSignal,
): Promise<TermResult[]> {
	const config = ajaxConfig();
	if (!config) {
		return [];
	}
	const body = new FormData();
	body.append('action', 'kk_search_terms');
	body.append('taxonomy', taxonomy);
	body.append('q', query);
	body.append('nonce', config.nonce);
	try {
		const response = await fetch(config.ajaxUrl, {
			method: 'POST',
			body,
			signal,
			credentials: 'same-origin',
		});
		if (!response.ok) {
			return [];
		}
		const payload = (await response.json()) as {
			success?: boolean;
			data?: { results?: TermResult[] };
		};
		if (!payload.success || !Array.isArray(payload.data?.results)) {
			return [];
		}
		return payload.data.results;
	} catch (error) {
		// Aborted (a newer keystroke superseded this one) or offline:
		// not an error worth surfacing, the list just stays as-is.
		if (error instanceof DOMException && error.name === 'AbortError') {
			return [];
		}
		return [];
	}
}

function debounce(
	fn: (query: string) => void,
	delay: number,
): (query: string) => void {
	let timer: number | undefined;
	return (query: string): void => {
		window.clearTimeout(timer);
		timer = window.setTimeout(() => fn(query), delay);
	};
}

function initTermSelect(container: HTMLElement): void {
	const trigger = container.querySelector<HTMLButtonElement>(
		'.kk-term-select-trigger',
	);
	const panel = container.querySelector<HTMLElement>('.kk-term-select-panel');
	const search = container.querySelector<HTMLInputElement>(
		'.kk-term-select-search',
	);
	const list = container.querySelector<HTMLElement>('.kk-term-select-list');
	const empty = container.querySelector<HTMLElement>('.kk-term-select-empty');
	const loading = container.querySelector<HTMLElement>(
		'.kk-term-select-loading',
	);
	const valueLabel = container.querySelector<HTMLElement>(
		'.kk-term-select-value',
	);
	const hidden = container.querySelector<HTMLInputElement>(
		'input[type="hidden"][data-customize-setting-link]',
	);
	if (!trigger || !panel || !search || !list || !valueLabel || !hidden) {
		return;
	}
	const taxonomy = container.dataset.taxonomy ?? 'category';
	const staticChoices: Record<string, string> = (() => {
		try {
			return JSON.parse(container.dataset.static ?? '{}') as Record<
				string,
				string
			>;
		} catch {
			return {};
		}
	})();
	let abort: AbortController | null = null;

	const setHighlighted = (option: HTMLElement | null): void => {
		list
			.querySelectorAll<HTMLElement>('.kk-term-select-option')
			.forEach((other) =>
				other.classList.toggle('is-highlighted', other === option),
			);
		if (option) {
			option.scrollIntoView({ block: 'nearest' });
		}
	};

	const visibleOptions = (): HTMLElement[] =>
		Array.from(
			list.querySelectorAll<HTMLElement>('.kk-term-select-option'),
		);

	const renderOptions = (results: TermResult[], query: string): void => {
		const needle = query.trim().toLowerCase();
		const current = hidden.value;
		list.innerHTML = '';
		let shown = 0;
		// Static choices first (e.g. "Automatic"), when they match.
		Object.entries(staticChoices).forEach(([value, label]) => {
			if (needle !== '' && !label.toLowerCase().includes(needle)) {
				return;
			}
			const option = document.createElement('li');
			option.className =
				'kk-term-select-option' + (value === current ? ' is-selected' : '');
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', value === current ? 'true' : 'false');
			option.dataset.value = value;
			option.textContent = label;
			list.appendChild(option);
			shown += 1;
		});
		results.forEach((result) => {
			const value = String(result.id);
			const option = document.createElement('li');
			option.className =
				'kk-term-select-option' + (value === current ? ' is-selected' : '');
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', value === current ? 'true' : 'false');
			option.dataset.value = value;
			option.textContent = result.text;
			list.appendChild(option);
			shown += 1;
		});
		if (empty) {
			empty.hidden = shown !== 0;
		}
		setHighlighted(visibleOptions()[0] ?? null);
	};

	const fetchResults = async (query: string): Promise<void> => {
		abort?.abort();
		abort = new AbortController();
		if (loading) {
			loading.hidden = false;
		}
		const results = await searchTerms(taxonomy, query, abort.signal);
		if (loading) {
			loading.hidden = true;
		}
		// A newer keystroke may have superseded this fetch; only render
		// when the search box still holds the query we fetched for.
		if (search.value.trim() !== query.trim()) {
			return;
		}
		renderOptions(results, query);
	};

	const open = (): void => {
		container.classList.add('is-open');
		trigger.setAttribute('aria-expanded', 'true');
		panel.hidden = false;
		search.value = '';
		search.focus();
		void fetchResults('');
	};

	const close = (): void => {
		abort?.abort();
		container.classList.remove('is-open');
		trigger.setAttribute('aria-expanded', 'false');
		panel.hidden = true;
		setHighlighted(null);
	};

	const select = (option: HTMLElement): void => {
		const value = option.dataset.value ?? '';
		const label = option.textContent?.trim() ?? '';
		valueLabel.textContent = label;
		if (hidden.value !== value) {
			hidden.value = value;
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}
		close();
		trigger.focus();
	};

	trigger.addEventListener('click', () => {
		if (container.classList.contains('is-open')) {
			close();
		} else {
			open();
		}
	});

	const debouncedFetch = debounce((query: string) => {
		void fetchResults(query);
	}, 250);
	search.addEventListener('input', () => {
		debouncedFetch(search.value);
	});

	list.addEventListener('click', (event) => {
		const option = (event.target as HTMLElement).closest<HTMLElement>(
			'.kk-term-select-option',
		);
		if (option) {
			select(option);
		}
	});

	search.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close();
			trigger.focus();
			return;
		}
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
			return;
		}
		event.preventDefault();
		const visible = visibleOptions();
		if (visible.length === 0) {
			return;
		}
		const current = visible.findIndex((option) =>
			option.classList.contains('is-highlighted'),
		);
		if (event.key === 'Enter') {
			const target = visible[current] ?? visible[0];
			if (target) {
				select(target);
			}
			return;
		}
		const step = event.key === 'ArrowDown' ? 1 : -1;
		const next =
			current === -1
				? 0
				: (current + step + visible.length) % visible.length;
		setHighlighted(visible[next] ?? null);
	});

	document.addEventListener('click', (event) => {
		if (
			container.classList.contains('is-open') &&
			!container.contains(event.target as Node)
		) {
			close();
		}
	});
}

function initTermMultiSelect(container: HTMLElement): void {
	const box = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-box',
	);
	const chips = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-chips',
	);
	const search = container.querySelector<HTMLInputElement>(
		'.kk-term-multiselect-search',
	);
	const panel = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-panel',
	);
	const list = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-list',
	);
	const empty = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-empty',
	);
	const loading = container.querySelector<HTMLElement>(
		'.kk-term-multiselect-loading',
	);
	const hidden = container.querySelector<HTMLInputElement>(
		'input[type="hidden"][data-customize-setting-link]',
	);
	if (!box || !chips || !search || !panel || !list || !hidden) {
		return;
	}
	const taxonomy = container.dataset.taxonomy ?? 'category';
	let abort: AbortController | null = null;

	const selectedIds = (): number[] => {
		try {
			const parsed: unknown = JSON.parse(hidden.value || '[]');
			if (!Array.isArray(parsed)) {
				return [];
			}
			return parsed
				.map((id) => Number(id))
				.filter((id) => Number.isInteger(id) && id > 0);
		} catch {
			return [];
		}
	};

	const sync = (): void => {
		const next = JSON.stringify(selectedIds());
		if (hidden.value !== next) {
			hidden.value = next;
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}
	};

	const setHighlighted = (option: HTMLElement | null): void => {
		list
			.querySelectorAll<HTMLElement>('.kk-term-multiselect-option')
			.forEach((other) =>
				other.classList.toggle('is-highlighted', other === option),
			);
		if (option) {
			option.scrollIntoView({ block: 'nearest' });
		}
	};

	const visibleOptions = (): HTMLElement[] =>
		Array.from(
			list.querySelectorAll<HTMLElement>('.kk-term-multiselect-option'),
		);

	const renderOptions = (results: TermResult[]): void => {
		const picked = new Set(selectedIds());
		list.innerHTML = '';
		let shown = 0;
		results.forEach((result) => {
			if (picked.has(result.id)) {
				return;
			}
			const option = document.createElement('li');
			option.className = 'kk-term-multiselect-option';
			option.setAttribute('role', 'option');
			option.setAttribute('aria-selected', 'false');
			option.dataset.value = String(result.id);
			option.textContent = result.text;
			list.appendChild(option);
			shown += 1;
		});
		if (empty) {
			empty.hidden = shown !== 0;
		}
		setHighlighted(visibleOptions()[0] ?? null);
	};

	const fetchResults = async (query: string): Promise<void> => {
		abort?.abort();
		abort = new AbortController();
		if (loading) {
			loading.hidden = false;
		}
		const results = await searchTerms(taxonomy, query, abort.signal);
		if (loading) {
			loading.hidden = true;
		}
		if (search.value.trim() !== query.trim()) {
			return;
		}
		renderOptions(results);
	};

	const open = (): void => {
		box.setAttribute('aria-expanded', 'true');
		panel.hidden = false;
		void fetchResults(search.value);
	};

	const close = (): void => {
		abort?.abort();
		box.setAttribute('aria-expanded', 'false');
		panel.hidden = true;
		setHighlighted(null);
	};

	const addChip = (id: number, label: string): void => {
		const ids = selectedIds();
		if (ids.includes(id)) {
			return;
		}
		ids.push(id);
		hidden.value = JSON.stringify(ids);
		const chip = document.createElement('span');
		chip.className = 'kk-term-multiselect-chip';
		chip.dataset.value = String(id);
		chip.textContent = label;
		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'kk-term-multiselect-remove';
		remove.setAttribute('aria-label', 'Remove');
		remove.textContent = '×';
		chip.appendChild(remove);
		chips.appendChild(chip);
		sync();
		search.value = '';
		search.placeholder = 'Add…';
		void fetchResults('');
	};

	const removeChip = (chip: HTMLElement): void => {
		const id = Number(chip.dataset.value ?? '0');
		chip.remove();
		const ids = selectedIds().filter((other) => other !== id);
		hidden.value = JSON.stringify(ids);
		sync();
		if (ids.length === 0) {
			search.placeholder = 'Search terms…';
		}
	};

	box.addEventListener('click', () => {
		search.focus();
		open();
	});

	search.addEventListener('focus', () => {
		open();
	});

	const debouncedFetch = debounce((query: string) => {
		void fetchResults(query);
	}, 250);
	search.addEventListener('input', () => {
		debouncedFetch(search.value);
	});

	list.addEventListener('click', (event) => {
		const option = (event.target as HTMLElement).closest<HTMLElement>(
			'.kk-term-multiselect-option',
		);
		if (!option) {
			return;
		}
		const id = Number(option.dataset.value ?? '0');
		const label = (option.textContent ?? '').trim().replace(/\s*\(\d+\)$/, '');
		if (id > 0) {
			addChip(id, label);
		}
		search.focus();
	});

	chips.addEventListener('click', (event) => {
		const remove = (event.target as HTMLElement).closest<HTMLElement>(
			'.kk-term-multiselect-remove',
		);
		if (!remove) {
			return;
		}
		const chip = remove.closest<HTMLElement>('.kk-term-multiselect-chip');
		if (chip) {
			removeChip(chip);
		}
		event.stopPropagation();
	});

	search.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close();
			return;
		}
		// Backspace on an empty search removes the last chip (Select2).
		if (
			event.key === 'Backspace' &&
			search.value === '' &&
			chips.lastElementChild instanceof HTMLElement
		) {
			removeChip(chips.lastElementChild);
			return;
		}
		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
			return;
		}
		event.preventDefault();
		const visible = visibleOptions();
		if (visible.length === 0) {
			return;
		}
		const current = visible.findIndex((option) =>
			option.classList.contains('is-highlighted'),
		);
		if (event.key === 'Enter') {
			const target = visible[current] ?? visible[0];
			if (target) {
				const id = Number(target.dataset.value ?? '0');
				const label = (target.textContent ?? '')
					.trim()
					.replace(/\s*\(\d+\)$/, '');
				if (id > 0) {
					addChip(id, label);
				}
				search.focus();
			}
			return;
		}
		const step = event.key === 'ArrowDown' ? 1 : -1;
		const next =
			current === -1
				? 0
				: (current + step + visible.length) % visible.length;
		setHighlighted(visible[next] ?? null);
	});

	document.addEventListener('click', (event) => {
		if (
			!panel.hidden &&
			!container.contains(event.target as Node)
		) {
			close();
		}
	});
}

document.addEventListener('DOMContentLoaded', () => {
	document
		.querySelectorAll<HTMLElement>('[data-kk-term-select]')
		.forEach(initTermSelect);
	document
		.querySelectorAll<HTMLElement>('[data-kk-term-multiselect]')
		.forEach(initTermMultiSelect);
	document
		.querySelectorAll<HTMLElement>('[data-kk-multi-image]')
		.forEach(initMultiImage);
});

/**
 * Multi-image picker (MultiImageControl): a dynamic list of
 * thumbnails with an "Add images" button that opens the media
 * library (multiple selection). The selection syncs into the hidden,
 * setting-linked input as a JSON array of attachment IDs.
 *
 * Uses the `wp.media` frame the Customizer already loads for the
 * media controls; if it is unavailable the button simply does
 * nothing (the saved value stays intact).
 */

interface MediaAttachment {
	toJSON(): {
		id?: number;
		sizes?: { thumbnail?: { url?: string } };
		url?: string;
	};
}

interface MediaSelection {
	each(callback: (attachment: MediaAttachment) => void): void;
}

interface MediaState {
	get(key: 'selection'): MediaSelection;
}

interface MediaFrame {
	on(event: 'select', callback: () => void): void;
	state(): MediaState;
	open(): void;
}

function initMultiImage(container: HTMLElement): void {
	const list = container.querySelector<HTMLElement>('.kk-multi-image-list');
	const addButton = container.querySelector<HTMLButtonElement>(
		'.kk-multi-image-add',
	);
	const hidden = container.querySelector<HTMLInputElement>(
		'input[type="hidden"][data-customize-setting-link]',
	);
	if (!list || !addButton || !hidden) {
		return;
	}

	const selectedIds = (): number[] => {
		try {
			const parsed: unknown = JSON.parse(hidden.value || '[]');
			if (!Array.isArray(parsed)) {
				return [];
			}
			return parsed
				.map((id) => Number(id))
				.filter((id) => Number.isInteger(id) && id > 0);
		} catch {
			return [];
		}
	};

	const syncFromDom = (): void => {
		const ids = Array.from(
			list.querySelectorAll<HTMLElement>('.kk-multi-image-item'),
		)
			.map((item) => Number(item.dataset.value ?? '0'))
			.filter((id) => id > 0);
		const next = JSON.stringify(ids);
		if (hidden.value !== next) {
			hidden.value = next;
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}
	};

	const addItem = (id: number, thumbUrl: string): void => {
		if (list.querySelector(`[data-value="${id}"]`)) {
			return;
		}
		const item = document.createElement('span');
		item.className = 'kk-multi-image-item';
		item.dataset.value = String(id);
		const img = document.createElement('img');
		img.src = thumbUrl;
		img.alt = '';
		img.width = 64;
		img.height = 64;
		const remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'kk-multi-image-remove';
		remove.setAttribute('aria-label', 'Remove image');
		remove.textContent = '×';
		item.appendChild(img);
		item.appendChild(remove);
		list.appendChild(item);
	};

	list.addEventListener('click', (event) => {
		const remove = (event.target as HTMLElement).closest<HTMLElement>(
			'.kk-multi-image-remove',
		);
		if (!remove) {
			return;
		}
		const item = remove.closest<HTMLElement>('.kk-multi-image-item');
		if (!item) {
			return;
		}
		item.remove();
		syncFromDom();
	});

	addButton.addEventListener('click', () => {
		const media = (
			window as unknown as {
				wp?: { media?: (options: Record<string, unknown>) => MediaFrame };
			}
		).wp?.media;
		if (!media) {
			return;
		}
		const frame = media({
			title: 'Select images',
			button: { text: 'Add images' },
			multiple: true,
			library: { type: 'image' },
		});
		frame.on('select', () => {
			const selection = frame.state().get('selection');
			selection.each((attachment) => {
				const json = attachment.toJSON();
				const id = Number(json.id ?? 0);
				if (id <= 0) {
					return;
				}
				const thumb = json.sizes?.thumbnail?.url ?? json.url ?? '';
				if (thumb === '') {
					return;
				}
				addItem(id, thumb);
			});
			syncFromDom();
		});
		frame.open();
	});
}
