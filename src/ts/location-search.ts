/**
 * Koperasi Kita - inline location search for agenda events.
 *
 * One combobox, three places: the Event Details box in the agenda
 * editor, the add/edit form on the Locations admin page, and the
 * contributor submission form. Typing searches the saved locations
 * first (instant, no network - they ride along as JSON on the field
 * itself), then the geocoder through `kk_agenda_place_search`
 * (debounced, so a fast typist costs one request, not one per key).
 *
 * Picking a row fills the location name and the coordinates: in the
 * picker context the hidden lat/lng/description inputs of the event
 * (the same inputs the Pick Location popup writes), in the
 * location-form context the Name / Address / Latitude / Longitude
 * fields of the form. Editing the text after a pick clears the
 * coordinates again, so a stale pin can never survive a rename -
 * free text without coordinates is still allowed, exactly as before.
 *
 * Keyboard: Down/Up moves through the results, Enter picks the
 * highlighted row (or the first one), Escape closes the list.
 * Results are real <button>s with 44px+ targets: touch-first, no
 * hover-only behaviour. Everything the list shows is built with
 * textContent, never innerHTML, so a place name can never inject
 * markup.
 */

import { postAjax } from './api';

export interface PlaceResult {
	source: 'saved' | 'geocoded';
	id: string;
	name: string;
	address: string;
	latitude: number;
	longitude: number;
	description: string;
}

export interface SavedLocation {
	id?: string;
	name: string;
	address: string;
	lat: number;
	lng: number;
	description: string;
}

export interface AjaxConfig {
	ajaxUrl: string;
	nonce: string;
}

const MIN_QUERY = 2;
const SAVED_LIMIT = 5;
const TOTAL_LIMIT = 8;
const SEARCH_DEBOUNCE_MS = 400;

/**
 * Saved locations whose name or address contains the query, best
 * matches first in stored order. Pure, so it is unit-testable
 * without a DOM.
 */
export function filterSavedLocations(saved: SavedLocation[], query: string): SavedLocation[] {
	const needle = query.trim().toLocaleLowerCase();

	if (needle.length < MIN_QUERY) {
		return [];
	}

	return saved
		.filter((row) => `${row.name} ${row.address}`.toLocaleLowerCase().includes(needle))
		.slice(0, SAVED_LIMIT);
}

/** A coordinate from a string, or null when it is empty or out of range. */
function parseCoord(value: unknown, min: number, max: number): number | null {
	if (typeof value !== 'number' || !Number.isFinite(value) || value < min || value > max) {
		return null;
	}

	return value;
}

/** The saved-locations JSON on the field, cleaned: malformed rows are dropped. */
export function parseSavedLocations(raw: string | undefined): SavedLocation[] {
	if (!raw) {
		return [];
	}

	let data: unknown;

	try {
		data = JSON.parse(raw);
	} catch {
		return [];
	}

	if (!Array.isArray(data)) {
		return [];
	}

	const clean: SavedLocation[] = [];

	for (const row of data as unknown[]) {
		if (typeof row !== 'object' || row === null) {
			continue;
		}

		const candidate = row as Record<string, unknown>;
		const lat = parseCoord(candidate.lat, -90, 90);
		const lng = parseCoord(candidate.lng, -180, 180);

		if (typeof candidate.name !== 'string' || candidate.name.trim() === '' || lat === null || lng === null) {
			continue;
		}

		clean.push({
			id: typeof candidate.id === 'string' ? candidate.id : undefined,
			name: candidate.name,
			address: typeof candidate.address === 'string' ? candidate.address : '',
			lat,
			lng,
			description: typeof candidate.description === 'string' ? candidate.description : '',
		});
	}

	return clean;
}

/**
 * Merge geocoded rows after the saved ones, skipping any row that
 * sits on saved coordinates - the saved row already carries the
 * curated name for that place. Pure, so it is unit-testable.
 */
export function mergePlaceResults(saved: PlaceResult[], geocoded: PlaceResult[]): PlaceResult[] {
	const merged = [...saved];

	for (const row of geocoded) {
		if (merged.length >= TOTAL_LIMIT) {
			break;
		}

		const duplicate = merged.some(
			(kept) => Math.abs(kept.latitude - row.latitude) < 0.01 && Math.abs(kept.longitude - row.longitude) < 0.01,
		);

		if (duplicate) {
			continue;
		}

		merged.push(row);
	}

	return merged;
}

function toPlaceResult(row: SavedLocation): PlaceResult {
	return {
		source: 'saved',
		id: row.id ?? '',
		name: row.name,
		address: row.address,
		latitude: row.lat,
		longitude: row.lng,
		description: row.description,
	};
}

export function initLocationSearch(root: HTMLElement, config: AjaxConfig | null): void {
	const input = root.querySelector<HTMLInputElement>('input');
	const list = root.querySelector<HTMLUListElement>('ul');

	if (!input || !list) {
		return;
	}

	// Aliases with the narrowed (non-null) types: the guard above
	// proves these exist, but TypeScript does not carry that narrowing
	// into the closures below, so the aliases carry it instead.
	const searchInput = input;
	const resultsList = list;

	const context = root.dataset.kkAgendaLocationContext === 'location-form' ? 'location-form' : 'picker';
	const saved = parseSavedLocations(root.dataset.kkAgendaSavedLocations);
	const searchingText = root.dataset.kkAgendaSearching ?? 'Searching...';
	const noResultsText = root.dataset.kkAgendaNoResults ?? 'No places found';
	const unavailableText = root.dataset.kkAgendaSearchUnavailable ?? 'Search is unavailable right now.';
	const savedLabel = root.dataset.kkAgendaSavedLabel ?? 'Saved location';
	const placeLabel = root.dataset.kkAgendaPlaceLabel ?? 'Places';

	// The picker block lives in the same form as the field (the
	// #post form in the editor, the submit form on the frontend), so
	// the scope is the form - never the whole document, where a
	// second picker could answer instead.
	const scope = root.closest('form') ?? root.parentElement ?? document;
	const latInput = scope.querySelector<HTMLInputElement>('[data-kk-agenda-lat]');
	const lngInput = scope.querySelector<HTMLInputElement>('[data-kk-agenda-lng]');
	const descInput = scope.querySelector<HTMLInputElement>('[data-kk-agenda-location-desc]');
	const addressInput = scope.querySelector<HTMLInputElement>('[data-kk-agenda-location-address]');
	const coordsEl = scope.querySelector<HTMLElement>('[data-kk-agenda-coords]');
	const detailEl = scope.querySelector<HTMLElement>('[data-kk-agenda-location-detail]');
	const saveWrap = scope.querySelector<HTMLElement>('[data-kk-agenda-save-location]');
	const saveCheckbox = saveWrap?.querySelector<HTMLInputElement>('input[type="checkbox"]') ?? null;
	const pickerBlock = scope.querySelector<HTMLElement>('[data-kk-agenda-map-picker]');
	const noPinText = pickerBlock?.dataset.kkAgendaNoPin ?? '';

	let results: PlaceResult[] = [];
	let activeIndex = -1;
	let pickedName: string | null = null;
	let searchTimer: number | undefined;
	let requestSeq = 0;

	function close(): void {
		resultsList.hidden = true;
		resultsList.innerHTML = '';
		results = [];
		activeIndex = -1;
		searchInput.setAttribute('aria-expanded', 'false');
		searchInput.removeAttribute('aria-activedescendant');
	}

	function open(): void {
		resultsList.hidden = false;
		searchInput.setAttribute('aria-expanded', 'true');
	}

	function setActive(index: number): void {
		activeIndex = index;
		const buttons = Array.from(resultsList.querySelectorAll<HTMLButtonElement>('.kk-agenda-location-result'));

		buttons.forEach((button, i) => {
			button.classList.toggle('is-active', i === index);
		});

		if (index >= 0 && results[index]) {
			searchInput.setAttribute('aria-activedescendant', `${resultsList.id}-option-${index}`);
		} else {
			searchInput.removeAttribute('aria-activedescendant');
		}
	}

	function statusRow(text: string): HTMLLIElement {
		const item = document.createElement('li');
		item.className = 'kk-agenda-location-status';
		item.textContent = text;
		return item;
	}

	function render(rows: PlaceResult[], status: string | null): void {
		results = rows;
		activeIndex = -1;
		resultsList.innerHTML = '';

		rows.forEach((row, index) => {
			const item = document.createElement('li');
			item.id = `${resultsList.id}-option-${index}`;
			item.setAttribute('role', 'option');

			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'kk-agenda-location-result';

			const name = document.createElement('span');
			name.className = 'kk-agenda-location-result-name';
			name.textContent = row.name;
			button.append(name);

			const meta = document.createElement('span');
			meta.className = 'kk-agenda-location-result-meta';
			const badge = row.source === 'saved' ? savedLabel : placeLabel;
			meta.textContent = row.address !== '' ? `${row.address} - ${badge}` : badge;
			button.append(meta);

			button.addEventListener('click', () => pick(row));
			item.append(button);
			resultsList.append(item);
		});

		if (status !== null) {
			resultsList.append(statusRow(status));
		}

		if (rows.length === 0 && status === null) {
			close();
			return;
		}

		open();
	}

	/** Clear every coordinate the field owns: the pin must never outlive its name. */
	function clearPick(): void {
		if (latInput) {
			latInput.value = '';
			latInput.dispatchEvent(new Event('change', { bubbles: true }));
		}
		if (lngInput) {
			lngInput.value = '';
			lngInput.dispatchEvent(new Event('change', { bubbles: true }));
		}
		if (descInput) {
			descInput.value = '';
		}
		if (addressInput) {
			addressInput.value = '';
		}
		if (coordsEl) {
			coordsEl.textContent = noPinText;
		}
		if (detailEl) {
			detailEl.textContent = '';
		}
		if (saveWrap) {
			saveWrap.hidden = true;
		}
	}

	function pick(place: PlaceResult): void {
		searchInput.value = place.name;
		pickedName = place.name;
		close();

		if (context === 'location-form') {
			const nameField = scope.querySelector<HTMLInputElement>('#kk-location-name');
			const addressField = scope.querySelector<HTMLInputElement>('#kk-location-address');
			const latField = scope.querySelector<HTMLInputElement>('#kk-location-lat');
			const lngField = scope.querySelector<HTMLInputElement>('#kk-location-lng');

			if (nameField) {
				nameField.value = place.name;
			}
			if (addressField) {
				addressField.value = place.address;
			}
			if (latField) {
				latField.value = String(place.latitude);
				// A programmatic value set fires no event, so announce
				// it: the form's preview map follows these fields.
				latField.dispatchEvent(new Event('change', { bubbles: true }));
			}
			if (lngField) {
				lngField.value = String(place.longitude);
				lngField.dispatchEvent(new Event('change', { bubbles: true }));
			}
			if (coordsEl) {
				coordsEl.textContent = `${place.latitude}, ${place.longitude}`;
			}
			return;
		}

		if (latInput) {
			latInput.value = String(place.latitude);
		}
		if (lngInput) {
			lngInput.value = String(place.longitude);
		}
		if (descInput) {
			descInput.value = place.description;
		}
		if (addressInput) {
			addressInput.value = place.address;
		}
		if (coordsEl) {
			coordsEl.textContent = `${place.latitude}, ${place.longitude}`;
		}
		if (detailEl) {
			detailEl.textContent = place.address;
		}

		// A saved place is already in the shared list; only a new
		// (geocoded) place offers to be saved for reuse.
		if (saveWrap) {
			saveWrap.hidden = place.source !== 'geocoded';
			if (place.source === 'geocoded' && saveCheckbox) {
				saveCheckbox.checked = true;
			}
		}
	}

	async function runSearch(): Promise<void> {
		const query = searchInput.value.trim();

		if (query.length < MIN_QUERY) {
			close();
			return;
		}

		const savedMatches = filterSavedLocations(saved, query).map(toPlaceResult);

		if (config === null) {
			render(savedMatches, savedMatches.length === 0 ? noResultsText : null);
			return;
		}

		render(savedMatches, searchingText);

		const seq = ++requestSeq;

		let data: { results: PlaceResult[] };
		try {
			data = await postAjax<{ results: PlaceResult[] }>(config, 'kk_agenda_place_search', { query });
		} catch {
			// The geocoder failed; the saved matches stay listed
			// instead of the whole search going blank.
			if (seq !== requestSeq) {
				return;
			}
			render(savedMatches, unavailableText);
			return;
		}

		if (seq !== requestSeq) {
			return;
		}

		const merged = mergePlaceResults(savedMatches, Array.isArray(data.results) ? data.results : []);
		render(merged, merged.length === 0 ? noResultsText : null);
	}

	function scheduleSearch(): void {
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(() => {
			void runSearch();
		}, SEARCH_DEBOUNCE_MS);
	}

	searchInput.addEventListener('input', () => {
		// The name changed after a pick: the stored pin belongs to
		// the old name, so it goes - free text without coordinates
		// is still a valid location, exactly as before.
		if (pickedName !== null && searchInput.value !== pickedName) {
			pickedName = null;
			clearPick();
		}

		if (searchInput.value.trim().length < MIN_QUERY) {
			close();
			return;
		}

		// Saved matches show instantly; the geocoder follows debounced.
		const savedMatches = filterSavedLocations(saved, searchInput.value).map(toPlaceResult);
		render(savedMatches, config !== null ? searchingText : null);
		scheduleSearch();
	});

	searchInput.addEventListener('focus', () => {
		if (searchInput.value.trim().length >= MIN_QUERY && resultsList.hidden) {
			void runSearch();
		}
	});

	searchInput.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			close();
			return;
		}

		if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp' && event.key !== 'Enter') {
			return;
		}

		if (resultsList.hidden) {
			if (event.key === 'Enter') {
				return;
			}
			event.preventDefault();
			void runSearch();
			return;
		}

		event.preventDefault();

		if (event.key === 'ArrowDown') {
			setActive(results.length === 0 ? -1 : (activeIndex + 1) % results.length);
			return;
		}

		if (event.key === 'ArrowUp') {
			setActive(results.length === 0 ? -1 : (activeIndex - 1 + results.length) % results.length);
			return;
		}

		// Enter with the list open picks a row instead of submitting
		// the post form: the highlighted row, or the first one.
		const target = activeIndex >= 0 ? results[activeIndex] : results[0];
		if (target) {
			pick(target);
		}
	});

	document.addEventListener('pointerdown', (event) => {
		if (!root.contains(event.target as Node)) {
			close();
		}
	});
}
