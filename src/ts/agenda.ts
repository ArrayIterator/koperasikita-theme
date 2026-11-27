/**
 * Koperasi Kita — agenda submission form and event maps.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when a `[kk_agenda_submit]` form, an event map, a location picker or
 * the Locations settings page is on the page; anything else on the site
 * never loads this file, and even if it did, there is nothing for it to
 * do.
 *
 * The form works without JavaScript — the server validates everything and
 * redirects after submit — so this is progressive enhancement only:
 * client-side checks with inline errors, today as the earliest date, the
 * end time kept after the start time, and the submit button disabled while
 * a submission is in flight so a double tap cannot send the form twice.
 * All controls are real inputs and buttons with 44px+ touch targets; no
 * hover-only behaviour anywhere.
 *
 * The maps are Leaflet with OpenStreetMap tiles (free, no key, no
 * billing account): the embedded map on a single event, and the Pick
 * Location popup shared by the admin meta box and the contributor form.
 * The popup stages its edits — opening copies the picker's values in,
 * "Use this location" copies them back, closing discards them — so
 * nothing is saved until the post is saved or the form is submitted.
 */

import * as L from 'leaflet';
import { initLocationSearch, type AjaxConfig } from './location-search';

export { filterSavedLocations, mergePlaceResults, parseSavedLocations } from './location-search';

function $all<T extends Element>(selector: string, root: ParentNode = document): T[] {
	return Array.from(root.querySelectorAll<T>(selector));
}

function fieldErrorId(input: HTMLInputElement | HTMLTextAreaElement): string {
	return `${input.id}-error`;
}

function showError(input: HTMLInputElement | HTMLTextAreaElement, message: string): void {
	input.setAttribute('aria-invalid', 'true');
	const existing = document.getElementById(fieldErrorId(input));
	if (existing && existing.classList.contains('kk-agenda-error')) {
		existing.textContent = message;
		return;
	}
	const error = document.createElement('span');
	error.className = 'kk-agenda-error';
	error.id = fieldErrorId(input);
	error.textContent = message;
	input.setAttribute('aria-describedby', error.id);
	input.insertAdjacentElement('afterend', error);
}

function clearError(input: HTMLInputElement | HTMLTextAreaElement): void {
	input.removeAttribute('aria-invalid');
	const describedBy = input.getAttribute('aria-describedby');
	if (describedBy) {
		input.removeAttribute('aria-describedby');
	}
	const error = document.getElementById(fieldErrorId(input));
	if (error && error.classList.contains('kk-agenda-error')) {
		error.remove();
	}
}

function initAgendaForm(form: HTMLFormElement): void {
	const title = form.querySelector<HTMLInputElement>('#kk-agenda-title');
	const date = form.querySelector<HTMLInputElement>('#kk-agenda-date');
	const startTime = form.querySelector<HTMLInputElement>('#kk-agenda-start-time');
	const endTime = form.querySelector<HTMLInputElement>('#kk-agenda-end-time');
	const location = form.querySelector<HTMLInputElement>('#kk-agenda-location');
	const description = form.querySelector<HTMLTextAreaElement>('#kk-agenda-description');
	const submitButton = form.querySelector<HTMLButtonElement>('[data-kk-agenda-submit]');

	if (!title || !date || !startTime || !location || !description) {
		return;
	}

	// Aliases with the narrowed (non-null) types: the guard above proves
	// these exist, but TypeScript does not carry that narrowing into the
	// closures below, so the aliases carry it instead.
	const titleInput = title;
	const dateInput = date;
	const startTimeInput = startTime;
	const locationInput = location;
	const descriptionInput = description;

	// Today is the earliest date: the server rejects past events, and
	// the picker should not offer them in the first place.
	const today = new Date();
	const year = today.getFullYear();
	const month = String(today.getMonth() + 1).padStart(2, '0');
	const day = String(today.getDate()).padStart(2, '0');
	dateInput.min = `${year}-${month}-${day}`;

	// Keep the end time after the start time when both are set.
	function syncEndTime(): void {
		if (!endTime || endTime.value === '' || startTimeInput.value === '') {
			return;
		}
		if (endTime.value <= startTimeInput.value) {
			endTime.value = '';
		}
	}
	startTimeInput.addEventListener('change', syncEndTime);

	$all<HTMLInputElement | HTMLTextAreaElement>('input, textarea', form).forEach((input) => {
		input.addEventListener('input', () => clearError(input));
	});

	form.addEventListener('submit', (event) => {
		// A holder object, not a plain variable: assignments made inside
		// the check() closure below are visible to the code after it.
		const firstInvalid: { element: HTMLInputElement | HTMLTextAreaElement | null } = { element: null };

		function check(input: HTMLInputElement | HTMLTextAreaElement, valid: boolean, message: string): void {
			if (valid) {
				return;
			}
			showError(input, message);
			if (firstInvalid.element === null) {
				firstInvalid.element = input;
			}
		}

		check(titleInput, titleInput.value.trim().length >= 3, 'Please give the event a title of at least 3 characters.');
		check(titleInput, titleInput.value.trim().length <= 200, 'The title is too long. Please keep it under 200 characters.');

		const datePattern = /^\d{4}-\d{2}-\d{2}$/;
		check(dateInput, datePattern.test(dateInput.value), 'Please pick a valid date.');
		if (datePattern.test(dateInput.value) && dateInput.value < dateInput.min) {
			check(dateInput, false, 'The event date is in the past. Please pick a future date.');
		}

		const timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
		check(startTimeInput, timePattern.test(startTimeInput.value), 'Please pick a valid start time.');
		if (endTime && endTime.value !== '') {
			check(endTime, timePattern.test(endTime.value), 'Please pick a valid end time.');
			if (timePattern.test(endTime.value) && timePattern.test(startTimeInput.value) && endTime.value <= startTimeInput.value) {
				check(endTime, false, 'The end time must be after the start time.');
			}
		}

		check(locationInput, locationInput.value.trim().length >= 3, 'Please tell us where the event takes place (at least 3 characters).');
		check(locationInput, locationInput.value.trim().length <= 200, 'The location is too long. Please keep it under 200 characters.');
		check(descriptionInput, descriptionInput.value.trim().length > 0, 'Please write a short description of the event.');
		check(descriptionInput, descriptionInput.value.trim().length <= 5000, 'The description is too long. Please keep it under 5000 characters.');

		if (firstInvalid.element !== null) {
			event.preventDefault();
			firstInvalid.element.focus();
			return;
		}

		// Valid: lock the button so a double tap (or an impatient second
		// tap on Android) cannot submit the form twice. The server-side
		// rate limit is the backstop if this is bypassed.
		if (submitButton) {
			submitButton.disabled = true;
		}
	});
}

function initAgenda(): void {
	$all<HTMLFormElement>('[data-kk-agenda-form]').forEach((form) => initAgendaForm(form));
}

/* --- Maps (Leaflet + OpenStreetMap) -------------------------------------- */
/* Free and keyless, so no billing account and no API key: the tiles load
 * on demand and the library only loads on agenda pages. The attribution
 * is the Leaflet default ("Leaflet | © OpenStreetMap contributors") —
 * the ODbL licence requires it, so it stays. */

const TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const TILE_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const SEARCH_DEBOUNCE_MS = 400;
const DEFAULT_ZOOM = 15;

function escapeHtml(text: string): string {
	return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** A coordinate from a string, or null when it is empty or out of range. */
function parseCoord(value: string, min: number, max: number): number | null {
	if (value.trim() === '') {
		return null;
	}
	const n = Number(value);
	if (!Number.isFinite(n) || n < min || n > max) {
		return null;
	}
	return n;
}

/** The KK-green map pin: a CSS teardrop, no marker-image assets. The
 * teardrop's tip sits ~6px below the 28px box, so the anchor is
 * [14, 34]: the tip, not the box, marks the place (see agenda.css). */
function makePin(): L.DivIcon {
	return L.divIcon({
		className: 'kk-agenda-pin-icon',
		html: '<span class="kk-agenda-pin"></span>',
		iconSize: [28, 28],
		iconAnchor: [14, 34],
	});
}

function addTileLayer(map: L.Map): void {
	L.tileLayer(TILE_URL, { attribution: TILE_ATTRIBUTION, maxZoom: 19 }).addTo(map);
}

/* --- Display maps (single event view) ------------------------------------ */

function initDisplayMap(el: HTMLElement): void {
	const lat = parseCoord(el.dataset.kkAgendaLat ?? '', -90, 90);
	const lng = parseCoord(el.dataset.kkAgendaLng ?? '', -180, 180);

	if (lat === null || lng === null) {
		return;
	}

	// The container is display: none until .is-ready, and Leaflet needs
	// a laid-out box: show it first, then build the map into it.
	el.classList.add('is-ready');
	const map = L.map(el, { scrollWheelZoom: false }).setView([lat, lng], DEFAULT_ZOOM);
	addTileLayer(map);
	const marker = L.marker([lat, lng], { icon: makePin() }).addTo(map);

	const label = (el.dataset.kkAgendaLabel ?? '').trim();
	const desc = (el.dataset.kkAgendaDesc ?? '').trim();
	if (label !== '' || desc !== '') {
		const parts: string[] = [];
		if (label !== '') {
			parts.push(`<strong>${escapeHtml(label)}</strong>`);
		}
		if (desc !== '') {
			parts.push(escapeHtml(desc));
		}
		marker.bindPopup(parts.join('<br>'));
	}

	requestAnimationFrame(() => map.invalidateSize());
}

/* --- Location preview map (Locations admin form) ----------------------- */
/* The form's own Leaflet map: the pin follows the Latitude /
 * Longitude fields — typed by hand, picked from a search result, or
 * applied from the Pick Location popup — and the map is centred on
 * the picker's default location with no pin until both fields hold a
 * valid coordinate. The fields are the source of truth, so a
 * programmatic fill announces itself with a change event the map
 * listens for, exactly like a manual edit. */
function initLocationPreviewMap(el: HTMLElement): void {
	const form = el.closest('form');
	const latInput = form?.querySelector<HTMLInputElement>('[data-kk-agenda-lat]') ?? null;
	const lngInput = form?.querySelector<HTMLInputElement>('[data-kk-agenda-lng]') ?? null;

	if (!latInput || !lngInput) {
		return;
	}

	const picker = el.closest<HTMLElement>('[data-kk-agenda-map-picker]');
	const defaultLat = parseCoord(picker?.dataset.kkAgendaDefaultLat ?? '', -90, 90) ?? -0.9471;
	const defaultLng = parseCoord(picker?.dataset.kkAgendaDefaultLng ?? '', -180, 180) ?? 100.4172;
	// The coords line under the button shows what the fields hold, so
	// it has to follow manual typing too — not just picks.
	const coordsEl = picker?.querySelector<HTMLElement>('[data-kk-agenda-coords]') ?? null;
	const noPin = picker?.dataset.kkAgendaNoPin ?? 'No pin placed';

	// Same rule as the display map: the container is display: none
	// until .is-ready, so show it before Leaflet measures it.
	el.classList.add('is-ready');
	const map = L.map(el, { scrollWheelZoom: false }).setView([defaultLat, defaultLng], DEFAULT_ZOOM);
	addTileLayer(map);

	let marker: L.Marker | null = null;

	// Arrow function, not a hoisted declaration: the null guard above
	// narrows latInput/lngInput, and only a non-hoisted closure keeps
	// that narrowing (a function declaration would lose it).
	const syncPin = (): void => {
		const lat = parseCoord(latInput.value, -90, 90);
		const lng = parseCoord(lngInput.value, -180, 180);

		if (lat === null || lng === null) {
			if (marker !== null) {
				marker.remove();
				marker = null;
			}
			if (coordsEl !== null) {
				coordsEl.textContent = noPin;
			}
			return;
		}

		if (marker === null) {
			marker = L.marker([lat, lng], { icon: makePin() }).addTo(map);
		} else {
			marker.setLatLng([lat, lng]);
		}

		if (coordsEl !== null) {
			coordsEl.textContent = `${lat}, ${lng}`;
		}
		map.setView([lat, lng], map.getZoom());
	};

	[latInput, lngInput].forEach((input) => {
		input.addEventListener('input', syncPin);
		input.addEventListener('change', syncPin);
	});

	syncPin();
	requestAnimationFrame(() => map.invalidateSize());
}

/* --- Pick Location popup -------------------------------------------------- */
/* One popup per page, shared by every picker block (the admin meta box
 * and the contributor form never share a page, but the code does not
 * assume that). Opening stages the active block's values into the
 * popup; "Use this location" copies them back; closing discards them. */

interface PickerBlock {
	block: HTMLElement;
	latInput: HTMLInputElement;
	lngInput: HTMLInputElement;
	// The agenda editor keeps this as a hidden input; the Locations
	// form wires its visible description textarea instead, so the
	// union covers both. Either way only .value is read or written.
	descInput: HTMLInputElement | HTMLTextAreaElement;
	coords: HTMLElement;
	pickButton: HTMLButtonElement;
	noPin: string;
	defaultLat: number;
	defaultLng: number;
}

interface NominatimResult {
	lat: string;
	lon: string;
	display_name: string;
}

let activePicker: PickerBlock | null = null;
let lastFocused: HTMLElement | null = null;
let popupMap: L.Map | null = null;
let popupMarker: L.Marker | null = null;

function pickerFromBlock(block: HTMLElement): PickerBlock | null {
	// The block's own inputs first (the agenda editor: hidden inputs
	// inside the block). The Locations form keeps its visible
	// Latitude / Longitude / Description fields in their own table
	// rows, so fall back to the form that owns the block — the same
	// scope location-search.ts already uses for the search field.
	const form = block.closest('form');
	const latInput = block.querySelector<HTMLInputElement>('[data-kk-agenda-lat]')
		?? form?.querySelector<HTMLInputElement>('[data-kk-agenda-lat]')
		?? null;
	const lngInput = block.querySelector<HTMLInputElement>('[data-kk-agenda-lng]')
		?? form?.querySelector<HTMLInputElement>('[data-kk-agenda-lng]')
		?? null;
	const descInput = block.querySelector<HTMLInputElement | HTMLTextAreaElement>('[data-kk-agenda-location-desc]')
		?? form?.querySelector<HTMLInputElement | HTMLTextAreaElement>('[data-kk-agenda-location-desc]')
		?? null;
	const coords = block.querySelector<HTMLElement>('[data-kk-agenda-coords]');
	const pickButton = block.querySelector<HTMLButtonElement>('[data-kk-agenda-pick]');

	if (!latInput || !lngInput || !descInput || !coords || !pickButton) {
		return null;
	}

	return {
		block,
		latInput,
		lngInput,
		descInput,
		coords,
		pickButton,
		noPin: block.dataset.kkAgendaNoPin ?? 'No pin placed',
		defaultLat: parseCoord(block.dataset.kkAgendaDefaultLat ?? '', -90, 90) ?? -0.9471,
		defaultLng: parseCoord(block.dataset.kkAgendaDefaultLng ?? '', -180, 180) ?? 100.4172,
	};
}

function initPickLocationPopup(): void {
	const popup = document.getElementById('kk-agenda-pick-location-popup');
	if (!popup) {
		return;
	}

	const searchInput = popup.querySelector<HTMLInputElement>('[data-kk-agenda-popup-search]');
	const results = popup.querySelector<HTMLElement>('[data-kk-agenda-popup-results]');
	const mapEl = popup.querySelector<HTMLElement>('[data-kk-agenda-popup-map]');
	const latInput = popup.querySelector<HTMLInputElement>('[data-kk-agenda-popup-lat]');
	const lngInput = popup.querySelector<HTMLInputElement>('[data-kk-agenda-popup-lng]');
	const descInput = popup.querySelector<HTMLInputElement>('[data-kk-agenda-popup-desc]');
	const savedSelect = popup.querySelector<HTMLSelectElement>('[data-kk-agenda-saved-location]');
	const applyButton = popup.querySelector<HTMLButtonElement>('[data-kk-agenda-popup-apply]');

	if (!searchInput || !results || !mapEl || !latInput || !lngInput || !descInput || !applyButton) {
		return;
	}

	// Aliases with the narrowed (non-null) types: the guard above proves
	// these exist, but TypeScript does not carry that narrowing into the
	// function declarations below (they are hoisted, so the analyser
	// reads them as if they could run before the guard), so the aliases
	// carry it instead.
	const popupEl = popup;
	const search = searchInput;
	const resultsList = results;
	const popupMapEl = mapEl;
	const popupLat = latInput;
	const popupLng = lngInput;
	const popupDesc = descInput;

	function hideResults(): void {
		resultsList.hidden = true;
		resultsList.innerHTML = '';
	}

	function setPopupPin(lat: number, lng: number): void {
		popupLat.value = String(lat);
		popupLng.value = String(lng);

		if (popupMap === null) {
			return;
		}

		if (popupMarker === null) {
			popupMarker = L.marker([lat, lng], { icon: makePin(), draggable: true }).addTo(popupMap);
			popupMarker.on('dragend', () => {
				const pos = popupMarker?.getLatLng();
				if (pos) {
					setPopupPin(pos.lat, pos.lng);
				}
			});
		} else {
			popupMarker.setLatLng([lat, lng]);
		}

		popupMap.setView([lat, lng], popupMap.getZoom());
	}

	function positionPopupPin(): void {
		const lat = parseCoord(popupLat.value, -90, 90);
		const lng = parseCoord(popupLng.value, -180, 180);

		if (lat === null || lng === null || popupMap === null) {
			return;
		}

		if (popupMarker === null) {
			popupMarker = L.marker([lat, lng], { icon: makePin(), draggable: true }).addTo(popupMap);
			popupMarker.on('dragend', () => {
				const pos = popupMarker?.getLatLng();
				if (pos) {
					setPopupPin(pos.lat, pos.lng);
				}
			});
		} else {
			popupMarker.setLatLng([lat, lng]);
		}

		popupMap.setView([lat, lng], DEFAULT_ZOOM);
	}

	function createPopupMap(picker: PickerBlock): void {
		// Same rule as the display map: the container is display: none
		// until .is-ready, so show it before Leaflet measures it.
		popupMapEl.classList.add('is-ready');
		const lat = parseCoord(popupLat.value, -90, 90);
		const lng = parseCoord(popupLng.value, -180, 180);
		const center: [number, number] = lat !== null && lng !== null ? [lat, lng] : [picker.defaultLat, picker.defaultLng];
		const map = L.map(popupMapEl, { scrollWheelZoom: false }).setView(center, DEFAULT_ZOOM);
		addTileLayer(map);
		map.on('click', (event: L.LeafletMouseEvent) => {
			setPopupPin(event.latlng.lat, event.latlng.lng);
		});
		popupMap = map;
		positionPopupPin();
		requestAnimationFrame(() => map.invalidateSize());
	}

	function openPopup(picker: PickerBlock): void {
		activePicker = picker;
		lastFocused = picker.pickButton;

		// Stage the picker's values into the popup; nothing is written
		// back until "Use this location".
		popupLat.value = picker.latInput.value;
		popupLng.value = picker.lngInput.value;
		popupDesc.value = picker.descInput.value;
		if (savedSelect) {
			savedSelect.value = '';
		}
		search.value = '';
		hideResults();

		popupEl.hidden = false;
		search.focus();

		requestAnimationFrame(() => {
			if (popupMap === null) {
				createPopupMap(picker);
			} else {
				popupMap.invalidateSize();
				positionPopupPin();
			}
		});
	}

	function closePopup(): void {
		popupEl.hidden = true;
		activePicker = null;
		hideResults();
		if (lastFocused) {
			lastFocused.focus();
			lastFocused = null;
		}
	}

	function applyPopup(): void {
		if (activePicker === null) {
			return;
		}

		const lat = parseCoord(popupLat.value, -90, 90);
		const lng = parseCoord(popupLng.value, -180, 180);
		const hasPin = lat !== null && lng !== null;

		activePicker.latInput.value = hasPin ? String(lat) : '';
		activePicker.lngInput.value = hasPin ? String(lng) : '';
		activePicker.descInput.value = popupDesc.value.trim().slice(0, 200);
		activePicker.coords.textContent = hasPin ? `${lat}, ${lng}` : activePicker.noPin;

		// A programmatic value set fires no event, so announce it:
		// the Locations form's preview map follows these inputs.
		activePicker.latInput.dispatchEvent(new Event('change', { bubbles: true }));
		activePicker.lngInput.dispatchEvent(new Event('change', { bubbles: true }));

		closePopup();
	}

	// The picker blocks: each Pick Location button opens the popup
	// for its own block. The block is display: none until .is-ready
	// (the popup needs JavaScript, so without it the button would be
	// dead) — mark it ready here, now that the popup is wired. Until
	// this line existed the button never appeared at all: nothing
	// ever added the class, so the Event Details box showed the
	// location text field and nothing else.
	$all<HTMLElement>('[data-kk-agenda-map-picker]').forEach((block) => {
		const picker = pickerFromBlock(block);
		if (picker === null) {
			return;
		}
		picker.block.classList.add('is-ready');
		picker.pickButton.addEventListener('click', () => openPopup(picker));
	});

	applyButton.addEventListener('click', applyPopup);

	$all<HTMLElement>('[data-kk-agenda-popup-close]', popup).forEach((button) => {
		button.addEventListener('click', closePopup);
	});

	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !popup.hidden) {
			closePopup();
		}
	});

	// Typing coordinates moves the pin; an invalid value leaves the
	// pin where it is, and Apply drops it silently.
	[popupLat, popupLng].forEach((input) => {
		input.addEventListener('change', () => {
			const lat = parseCoord(popupLat.value, -90, 90);
			const lng = parseCoord(popupLng.value, -180, 180);
			if (lat !== null && lng !== null) {
				setPopupPin(lat, lng);
			}
		});
	});

	// A saved location fills the pin and the description, and fills
	// the event's Location text only when it is still empty — never
	// overwriting what the editor or contributor already typed.
	if (savedSelect) {
		savedSelect.addEventListener('change', () => {
			const option = savedSelect.selectedOptions[0];
			if (!option || option.value === '') {
				return;
			}
			const lat = parseCoord(option.dataset.lat ?? '', -90, 90);
			const lng = parseCoord(option.dataset.lng ?? '', -180, 180);
			if (lat === null || lng === null) {
				return;
			}
			popupLat.value = String(lat);
			popupLng.value = String(lng);
			popupDesc.value = option.dataset.desc ?? '';
			setPopupPin(lat, lng);

			const locationInput = document.getElementById('kk-agenda-location');
			if (locationInput instanceof HTMLInputElement && locationInput.value.trim() === '') {
				locationInput.value = option.value;
			}
		});
	}

	// Place search (Nominatim, debounced): results are a plain list
	// under the search box; picking one moves the pin there.
	let searchTimer: number | undefined;
	search.addEventListener('input', () => {
		window.clearTimeout(searchTimer);
		searchTimer = window.setTimeout(() => {
			void runSearch(search.value.trim());
		}, SEARCH_DEBOUNCE_MS);
	});

	async function runSearch(query: string): Promise<void> {
		if (query === '') {
			hideResults();
			return;
		}

		let data: unknown;
		try {
			const response = await fetch(`${NOMINATIM_URL}?format=json&limit=5&accept-language=id&q=${encodeURIComponent(query)}`, {
				headers: { Accept: 'application/json' },
			});
			if (!response.ok) {
				hideResults();
				return;
			}
			data = await response.json();
		} catch {
			hideResults();
			return;
		}

		if (!Array.isArray(data)) {
			hideResults();
			return;
		}

		const found = (data as NominatimResult[]).filter((row) => typeof row.lat === 'string' && typeof row.lon === 'string' && typeof row.display_name === 'string');

		resultsList.innerHTML = '';

		if (found.length === 0) {
			const empty = document.createElement('li');
			empty.className = 'kk-agenda-popup-results-empty';
			empty.textContent = search.dataset.kkAgendaNoResults ?? 'No places found';
			resultsList.append(empty);
			resultsList.hidden = false;
			return;
		}

		found.forEach((row) => {
			const item = document.createElement('li');
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'kk-agenda-popup-result';
			button.textContent = row.display_name;
			button.addEventListener('click', () => {
				const lat = parseCoord(row.lat, -90, 90);
				const lng = parseCoord(row.lon, -180, 180);
				if (lat !== null && lng !== null) {
					setPopupPin(lat, lng);
				}
				hideResults();
				search.value = row.display_name;
			});
			item.append(button);
			resultsList.append(item);
		});

		resultsList.hidden = false;
	}
}

document.addEventListener('DOMContentLoaded', () => {
	initAgenda();
	$all<HTMLElement>('[data-kk-agenda-map]').forEach((el) => initDisplayMap(el));
	$all<HTMLElement>('[data-kk-location-preview-map]').forEach((el) => initLocationPreviewMap(el));
	initPickLocationPopup();
	initLocationSearchFields();
});

/**
 * The inline location search on every agenda location field: the
 * Event Details box, the Locations admin form, and the contributor
 * form. The admin script localises kkAgendaData; on the frontend
 * the shared kkData (ajaxUrl + the same nonce) is the fallback, so
 * the contributor form searches through the same endpoint.
 */
function initLocationSearchFields(): void {
	const adminData = (window as unknown as { kkAgendaData?: AjaxConfig }).kkAgendaData;
	const frontData = (window as unknown as { kkData?: AjaxConfig }).kkData;
	const config = adminData?.ajaxUrl && adminData?.nonce ? adminData : frontData?.ajaxUrl && frontData?.nonce ? frontData : null;

	$all<HTMLElement>('[data-kk-agenda-location-search]').forEach((root) => {
		initLocationSearch(root, config);
	});
}
