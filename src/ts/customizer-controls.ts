/**
 * Koperasi Kita — Customizer controls pane script.
 *
 * Syncs the TermCheckboxControl checkbox lists into their hidden,
 * setting-linked input as a JSON array of term IDs, so the Customizer
 * picks the selection up as one setting value. Filters those lists
 * through a search field. Powers the TermSelectControl searchable
 * dropdown (Select2-style, no jQuery): a trigger + a panel with a
 * search field and a filtered option list, syncing the pick into its
 * hidden, setting-linked input. Runs only in the Customizer controls
 * pane, never on the frontend.
 */

export {};

function syncTermCheckboxes(container: HTMLElement): void {
	const hidden = container.querySelector<HTMLInputElement>(
		'input[type="hidden"][data-customize-setting-link]',
	);
	const boxes = Array.from(
		container.querySelectorAll<HTMLInputElement>('input[type="checkbox"]'),
	);
	if (!hidden || boxes.length === 0) {
		return;
	}

	const update = (): void => {
		const ids = boxes
			.filter((box) => box.checked)
			.map((box) => Number(box.value))
			.filter((id) => Number.isInteger(id) && id > 0);
		const next = JSON.stringify(ids);
		if (hidden.value !== next) {
			hidden.value = next;
			hidden.dispatchEvent(new Event('change', { bubbles: true }));
		}
	};

	boxes.forEach((box) => box.addEventListener('change', update));
}

function initTermCheckboxFilter(container: HTMLElement): void {
	const search = container.querySelector<HTMLInputElement>(
		'.kk-term-checkbox-filter',
	);
	const list = container.querySelector<HTMLElement>('[data-kk-term-checkboxes]');
	if (!search || !list) {
		return;
	}
	const labels = Array.from(list.querySelectorAll<HTMLElement>('label'));
	search.addEventListener('input', () => {
		const needle = search.value.trim().toLowerCase();
		labels.forEach((label) => {
			const haystack = (label.textContent ?? '').toLowerCase();
			label.style.display =
				needle === '' || haystack.includes(needle) ? '' : 'none';
		});
	});
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
	const valueLabel = container.querySelector<HTMLElement>(
		'.kk-term-select-value',
	);
	const hidden = container.querySelector<HTMLInputElement>(
		'input[type="hidden"][data-customize-setting-link]',
	);
	if (!trigger || !panel || !search || !list || !valueLabel || !hidden) {
		return;
	}
	const options = Array.from(
		list.querySelectorAll<HTMLElement>('.kk-term-select-option'),
	);
	if (options.length === 0) {
		return;
	}

	const visibleOptions = (): HTMLElement[] =>
		options.filter((option) => option.style.display !== 'none');

	const setHighlighted = (option: HTMLElement | null): void => {
		options.forEach((other) =>
			other.classList.toggle('is-highlighted', other === option),
		);
		if (option) {
			option.scrollIntoView({ block: 'nearest' });
		}
	};

	const open = (): void => {
		container.classList.add('is-open');
		trigger.setAttribute('aria-expanded', 'true');
		panel.hidden = false;
		search.value = '';
		options.forEach((option) => {
			option.style.display = '';
		});
		if (empty) {
			empty.hidden = true;
		}
		setHighlighted(
			options.find((option) => option.classList.contains('is-selected')) ??
				options[0] ??
				null,
		);
		search.focus();
	};

	const close = (): void => {
		container.classList.remove('is-open');
		trigger.setAttribute('aria-expanded', 'false');
		panel.hidden = true;
		setHighlighted(null);
	};

	const select = (option: HTMLElement): void => {
		const value = option.dataset.value ?? '';
		const label = option.textContent?.trim() ?? '';
		options.forEach((other) => {
			const selected = other === option;
			other.classList.toggle('is-selected', selected);
			other.setAttribute('aria-selected', selected ? 'true' : 'false');
		});
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

	search.addEventListener('input', () => {
		const needle = search.value.trim().toLowerCase();
		let shown = 0;
		options.forEach((option) => {
			const haystack = (option.textContent ?? '').toLowerCase();
			const match = needle === '' || haystack.includes(needle);
			option.style.display = match ? '' : 'none';
			if (match) {
				shown += 1;
			}
		});
		if (empty) {
			empty.hidden = shown !== 0;
		}
		setHighlighted(visibleOptions()[0] ?? null);
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

document.addEventListener('DOMContentLoaded', () => {
	document
		.querySelectorAll<HTMLElement>('[data-kk-term-checkboxes]')
		.forEach((list) => {
			const container = list.closest<HTMLElement>('.customize-control');
			syncTermCheckboxes(list);
			if (container) {
				initTermCheckboxFilter(container);
			}
		});
	document
		.querySelectorAll<HTMLElement>('[data-kk-term-select]')
		.forEach(initTermSelect);
});
