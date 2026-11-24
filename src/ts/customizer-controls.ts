/**
 * Koperasi Kita — Customizer controls pane script.
 *
 * Syncs the TermCheckboxControl checkbox lists into their hidden,
 * setting-linked input as a JSON array of term IDs, so the Customizer
 * picks the selection up as one setting value. Runs only in the
 * Customizer controls pane, never on the frontend.
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

document.addEventListener('DOMContentLoaded', () => {
	document
		.querySelectorAll<HTMLElement>('[data-kk-term-checkboxes]')
		.forEach(syncTermCheckboxes);
});
