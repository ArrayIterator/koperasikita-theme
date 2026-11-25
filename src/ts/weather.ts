/**
 * Koperasi Kita — weather module (standalone).
 *
 * Guards itself on `window.kkData.isWeatherEnabled`: when the module is
 * disabled this file is not enqueued at all, and even if it were loaded
 * it would run zero code. All weather data comes from the theme's own
 * AJAX endpoints (which proxy the keyless Open-Meteo API and cache the
 * result in a transient); the browser never calls the provider directly.
 *
 * The visitor's picked location lives in localStorage only, so it is
 * per-browser and never touches the database.
 */

export {};

/* Local AJAX client (self-contained on purpose).
 *
 * This used to import postAjax from ./api, but Vite splits that shared
 * module into a separate chunk (api-*.js) and weather.js then starts with
 * a top-level `import` — an ES module. The theme enqueues its scripts as
 * classic <script> tags (no type="module" anywhere), so the browser
 * throws "Uncaught SyntaxError: Cannot use import statement outside a
 * module" and this whole file never runs: the top-bar weather slot stays
 * empty and the location picker never opens. Every other frontend entry
 * (main, gold-markets, pagination, agenda, pwa) is self-contained and
 * loads fine, so this file is self-contained too: the ~20-line envelope
 * client lives here instead of in the shared chunk. Keep it in sync
 * with src/ts/api.ts if the envelope ever changes. */

interface ApiEnvelope<T> {
	success: boolean;
	data: T | null;
	error: { code: string; message: string } | null;
}

interface AjaxConfig {
	ajaxUrl: string;
	nonce: string;
}

async function postAjax<T>(
	config: AjaxConfig,
	action: string,
	params: Record<string, string> = {},
	signal?: AbortSignal,
): Promise<T> {
	const body = new URLSearchParams({ action, nonce: config.nonce, ...params });
	const response = await fetch(config.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
		signal,
	});
	const json = (await response.json().catch(() => null)) as ApiEnvelope<T> | null;
	if (!response.ok || !json?.success || json.data === null) {
		throw new Error(json?.error?.message ?? `Request failed (${response.status}).`);
	}
	return json.data;
}

interface KkData {
	ajaxUrl?: string;
	nonce?: string;
	isWeatherEnabled?: boolean;
	// Minutes the browser may serve weather from localStorage before
	// fetching fresh data (Customizer: kk_weather_cache_lifetime).
	weatherCacheLifetimeMin?: number;
}

// No global augmentation here: src/ts/main.ts already declares
// window.kkData, and a second declaration with a different shape breaks
// the build. Read it through a narrow cast instead.

interface Location {
	name: string;
	latitude: number;
	longitude: number;
	source: 'default' | 'quick' | 'search' | 'coords' | 'geolocation';
}

interface WeatherData {
	name: string;
	latitude: number;
	longitude: number;
	temperature: number;
	feels_like: number | null;
	humidity: number | null;
	wind_speed: number | null;
	high: number | null;
	low: number | null;
	code: number;
	condition: string;
	// Rendered condition icon (SVG markup from the server). Present on
	// the top-bar response; older or widget-only shapes omit it.
	icon?: string;
}

interface SearchResult {
	name: string;
	latitude: number;
	longitude: number;
	country: string;
}

const LOCATION_KEY = 'kk-weather-location';
const CUSTOM_CITIES_KEY = 'kk-weather-cities';
const MAX_CUSTOM_CITIES = 8;

// Client-side weather cache: the last fetched conditions, kept in
// localStorage so the top bar can paint instantly on the next visit
// instead of waiting for a round-trip. Keyed by location (rounded
// coords) so a moved pin never serves another place's weather.
const WEATHER_CACHE_KEY = 'kk-weather-data';

interface CachedWeather {
	fetchedAt: number;
	latitude: number;
	longitude: number;
	weather: WeatherData;
}

// Cache lifetime in ms, from the Customizer (kk_weather_cache_lifetime,
// minutes). 0 disables the browser cache entirely.
function getCacheLifetimeMs(): number {
	const data = getKkData();
	const minutes =
		typeof data.weatherCacheLifetimeMin === 'number' && Number.isFinite(data.weatherCacheLifetimeMin)
			? data.weatherCacheLifetimeMin
			: 15;
	return Math.max(0, minutes) * 60 * 1000;
}

function getCachedWeather(location: Location): WeatherData | null {
	if (getCacheLifetimeMs() === 0) {
		return null;
	}
	try {
		const raw = window.localStorage.getItem(WEATHER_CACHE_KEY);
		if (!raw) {
			return null;
		}
		const cached = JSON.parse(raw) as Partial<CachedWeather>;
		if (!cached || typeof cached.fetchedAt !== 'number' || !cached.weather) {
			return null;
		}
		// Same place (within ~1 km) and still within the lifetime.
		if (
			Math.abs((cached.latitude ?? Number.NaN) - location.latitude) > 0.01 ||
			Math.abs((cached.longitude ?? Number.NaN) - location.longitude) > 0.01
		) {
			return null;
		}
		if (Date.now() - cached.fetchedAt > getCacheLifetimeMs()) {
			return null;
		}
		return cached.weather;
	} catch {
		return null;
	}
}

function setCachedWeather(location: Location, weather: WeatherData): void {
	if (getCacheLifetimeMs() === 0) {
		return;
	}
	try {
		const cached: CachedWeather = {
			fetchedAt: Date.now(),
			latitude: location.latitude,
			longitude: location.longitude,
			weather,
		};
		window.localStorage.setItem(WEATHER_CACHE_KEY, JSON.stringify(cached));
	} catch {
		// Private mode or full storage: the fetch still worked, the
		// next visit just fetches again.
	}
}

const DEFAULT_LOCATION: Location = {
	name: 'Padang',
	latitude: -0.9471,
	longitude: 100.4172,
	source: 'default',
};

function getKkData(): KkData {
	return (window as unknown as { kkData?: KkData }).kkData ?? {};
}

function $(selector: string, root: ParentNode = document): HTMLElement | null {
	return root.querySelector(selector);
}

function $all<T extends Element>(selector: string, root: ParentNode = document): T[] {
	return Array.from(root.querySelectorAll<T>(selector));
}

function formatCoords(location: Location): string {
	return `${location.latitude.toFixed(2)}, ${location.longitude.toFixed(2)}`;
}

/* ------------------------------------------------------------------ */
/* Stored location                                                     */
/* ------------------------------------------------------------------ */

function getStoredLocation(): Location | null {
	try {
		const raw = window.localStorage.getItem(LOCATION_KEY);
		if (!raw) {
			return null;
		}
		const parsed: unknown = JSON.parse(raw);
		if (typeof parsed !== 'object' || parsed === null) {
			return null;
		}
		const candidate = parsed as Partial<Location>;
		if (
			typeof candidate.name !== 'string' ||
			typeof candidate.latitude !== 'number' ||
			typeof candidate.longitude !== 'number' ||
			!Number.isFinite(candidate.latitude) ||
			!Number.isFinite(candidate.longitude) ||
			candidate.latitude < -90 ||
			candidate.latitude > 90 ||
			candidate.longitude < -180 ||
			candidate.longitude > 180
		) {
			return null;
		}
		return {
			name: candidate.name,
			latitude: candidate.latitude,
			longitude: candidate.longitude,
			source: 'default',
		};
	} catch {
		return null;
	}
}

function storeLocation(location: Location): void {
	try {
		window.localStorage.setItem(LOCATION_KEY, JSON.stringify(location));
	} catch {
		// Storage full or unavailable (private mode): the session keeps the
		// in-memory choice, it just does not survive a reload.
	}
}

/* A place is the same place when the names match (case-insensitive,
 * trimmed) or the coordinates are within ~0.01 degrees (about 1 km).
 * Name alone is not enough: geolocation and coordinates resolve to
 * "My location" / "Custom location", which would otherwise duplicate
 * a named city at the same spot. Coordinates alone are not enough
 * either: two spellings of one city share a name but may carry
 * slightly different coordinates from different sources. */
const COORD_TOLERANCE = 0.01;

function normalizePlaceName(name: string): string {
	return name.trim().toLowerCase();
}

function isSamePlace(a: Location, b: Location): boolean {
	const aName = normalizePlaceName(a.name);
	const bName = normalizePlaceName(b.name);
	if (aName !== '' && aName === bName) {
		return true;
	}
	return (
		Math.abs(a.latitude - b.latitude) < COORD_TOLERANCE &&
		Math.abs(a.longitude - b.longitude) < COORD_TOLERANCE
	);
}

function getCustomCities(): Location[] {
	try {
		const raw = window.localStorage.getItem(CUSTOM_CITIES_KEY);
		if (!raw) {
			return [];
		}
		const parsed: unknown = JSON.parse(raw);
		if (!Array.isArray(parsed)) {
			return [];
		}
		const valid = parsed.filter(
			(item): item is Location =>
				typeof item === 'object' &&
				item !== null &&
				typeof (item as Location).name === 'string' &&
				typeof (item as Location).latitude === 'number' &&
				typeof (item as Location).longitude === 'number',
		);
		// Self-heal: drop entries that duplicate a default quick pick or
		// an earlier entry in the list (same name or same coordinates),
		// and persist the cleaned list so a stale duplicate disappears
		// instead of lingering in the strip forever.
		const cleaned: Location[] = [];
		for (const city of valid) {
			const duplicatesDefault = quickCities.some((quick) => isSamePlace(quick, city));
			const duplicatesEarlier = cleaned.some((kept) => isSamePlace(kept, city));
			if (!duplicatesDefault && !duplicatesEarlier) {
				cleaned.push(city);
			}
		}
		if (cleaned.length !== valid.length) {
			storeCustomCities(cleaned);
		}
		return cleaned;
	} catch {
		return [];
	}
}

function storeCustomCities(cities: Location[]): void {
	try {
		window.localStorage.setItem(CUSTOM_CITIES_KEY, JSON.stringify(cities));
	} catch {
		// See storeLocation: keep going without persistence.
	}
}

/* ------------------------------------------------------------------ */
/* Server API                                                          */
/* ------------------------------------------------------------------ */

async function fetchWeather(location: Location): Promise<WeatherData> {
	const data = getKkData();
	if (!data.ajaxUrl || !data.nonce) {
		throw new Error('Weather endpoint is not available.');
	}
	const result = await postAjax<{ weather?: WeatherData }>(
		{ ajaxUrl: data.ajaxUrl, nonce: data.nonce },
		'kk_weather',
		{
			name: location.name,
			latitude: String(location.latitude),
			longitude: String(location.longitude),
		},
	);
	if (!result.weather) {
		throw new Error('Weather is unavailable right now.');
	}
	setCachedWeather(location, result.weather);
	return result.weather;
}

async function searchPlaces(query: string, signal: AbortSignal): Promise<SearchResult[]> {
	const data = getKkData();
	if (!data.ajaxUrl || !data.nonce) {
		throw new Error('Search endpoint is not available.');
	}
	const result = await postAjax<{ results?: SearchResult[] }>(
		{ ajaxUrl: data.ajaxUrl, nonce: data.nonce },
		'kk_weather_search',
		{ query },
		signal,
	);
	return Array.isArray(result.results) ? result.results : [];
}

/* Resolve coordinates to their area name ("Kabupaten Malang"), so a
 * GPS fix or a typed coordinate pair shows the place instead of the
 * anonymous "My location" / "Custom location". Null when the place
 * cannot be resolved — the caller keeps the generic label then. */
async function reverseGeocode(latitude: number, longitude: number): Promise<string | null> {
	const data = getKkData();
	if (!data.ajaxUrl || !data.nonce) {
		return null;
	}
	try {
		const result = await postAjax<{ name?: string | null }>(
			{ ajaxUrl: data.ajaxUrl, nonce: data.nonce },
			'kk_weather_reverse',
			{ latitude: String(latitude), longitude: String(longitude) },
		);
		const name = typeof result.name === 'string' ? result.name.trim() : '';
		return name !== '' ? name : null;
	} catch {
		return null;
	}
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

function renderTopbar(weather: WeatherData): void {
	const slot = $('#kk-topbar-weather');
	if (!slot) {
		return;
	}
	slot.textContent = '';
	slot.removeAttribute('aria-busy');
	// The condition icon rides in the response as the theme's own icon
	// SVG (trusted markup from the server, no user input in it).
	if (weather.icon) {
		const icon = document.createElement('span');
		icon.className = 'kk-topbar-weather-icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.innerHTML = weather.icon;
		slot.appendChild(icon);
	}
	const temp = document.createElement('span');
	temp.className = 'kk-topbar-weather-temp';
	temp.textContent = `${Math.round(weather.temperature)}°`;
	const place = document.createElement('span');
	place.className = 'kk-topbar-weather-place';
	place.textContent = weather.name;
	slot.append(temp, document.createTextNode(' '), place);
	slot.setAttribute('title', weather.condition);
}

// Loading state for the top bar, shown while the first fetch runs and
// there is no cached weather to paint. It carries the place name
// (known immediately from the stored location) plus a "—°" temperature,
// so the slot keeps its width and the top bar does not bounce when the
// real numbers land.
function renderTopbarLoading(location: Location): void {
	const slot = $('#kk-topbar-weather');
	if (!slot) {
		return;
	}
	slot.textContent = '';
	slot.setAttribute('aria-busy', 'true');
	const temp = document.createElement('span');
	temp.className = 'kk-topbar-weather-temp kk-topbar-weather-loading';
	temp.textContent = '—°';
	const place = document.createElement('span');
	place.className = 'kk-topbar-weather-place';
	place.textContent = location.name;
	slot.append(temp, document.createTextNode(' '), place);
	slot.setAttribute('title', 'Loading weather');
}

// Paint the top bar and any weather widgets from one weather object
// (fresh fetch or browser cache — the rendering is the same).
function renderWeatherDisplays(weather: WeatherData): void {
	renderTopbar(weather);
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
		const place = widget.querySelector<HTMLElement>('.kk-weather-place');
		if (place) {
			place.textContent = weather.name;
		}
		if (body) {
			renderWidgetBody(body, weather, widget.dataset.showDetails === '1');
		}
	});
}

function hideTopbar(): void {
	const slot = $('#kk-topbar-weather');
	if (slot) {
		// Empty slot: the theme CSS hides it entirely, so a failed fetch
		// leaves no gap and no fake numbers behind.
		slot.textContent = '';
		slot.removeAttribute('title');
		slot.removeAttribute('aria-busy');
	}
}

function renderWidgetBody(body: HTMLElement, weather: WeatherData, showDetails: boolean): void {
	body.textContent = '';
	const temp = document.createElement('p');
	temp.className = 'kk-weather-temp';
	temp.textContent = `${Math.round(weather.temperature)}°C`;
	body.appendChild(temp);
	const condition = document.createElement('p');
	condition.className = 'kk-weather-condition';
	condition.textContent = weather.condition;
	body.appendChild(condition);

	if (!showDetails) {
		return;
	}
	const rows: Array<[string, string]> = [];
	if (weather.feels_like !== null) {
		rows.push(['Feels like', `${Math.round(weather.feels_like)}°C`]);
	}
	if (weather.high !== null && weather.low !== null) {
		rows.push(['High / Low', `${Math.round(weather.high)}° / ${Math.round(weather.low)}°C`]);
	}
	if (weather.humidity !== null) {
		rows.push(['Humidity', `${weather.humidity}%`]);
	}
	if (weather.wind_speed !== null) {
		rows.push(['Wind', `${Math.round(weather.wind_speed)} km/h`]);
	}
	if (rows.length === 0) {
		return;
	}
	const list = document.createElement('ul');
	list.className = 'kk-weather-details';
	for (const [label, value] of rows) {
		const item = document.createElement('li');
		const labelEl = document.createElement('span');
		labelEl.textContent = label;
		const valueEl = document.createElement('strong');
		valueEl.textContent = value;
		item.append(labelEl, valueEl);
		list.appendChild(item);
	}
	body.appendChild(list);
}

function renderWidgetEmpty(body: HTMLElement): void {
	body.textContent = '';
	const empty = document.createElement('p');
	empty.className = 'kk-widget-empty';
	empty.textContent = 'Weather is unavailable right now.';
	body.appendChild(empty);
}

async function refreshWeatherDisplays(location: Location): Promise<void> {
	try {
		const weather = await fetchWeather(location);
		renderWeatherDisplays(weather);
	} catch {
		// A failed revalidation keeps a cached display (it is still
		// fresh); only a page with no data at all — still showing the
		// loading state — hides the slot. Widgets keep their
		// server-side first paint on failure: it shows the widget's
		// default city, labelled as such, so nothing is faked.
		const slot = $('#kk-topbar-weather');
		if (slot && slot.querySelector('.kk-topbar-weather-loading')) {
			hideTopbar();
		}
	}
}

async function refreshWidgetsFor(location: Location): Promise<void> {
	const widgets = $all<HTMLElement>('[data-kk-weather-widget]');
	if (widgets.length === 0) {
		return;
	}
	try {
		const weather = await fetchWeather(location);
		widgets.forEach((widget) => {
			const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
			const place = widget.querySelector<HTMLElement>('.kk-weather-place');
			if (place) {
				place.textContent = weather.name;
			}
			if (body) {
				renderWidgetBody(body, weather, widget.dataset.showDetails === '1');
			}
		});
	} catch {
		widgets.forEach((widget) => {
			// Keep the server-side first paint when it already shows the
			// stored location; only a stale paint gets the honest empty.
			const defaultLat = Number(widget.dataset.defaultLatitude ?? 0);
			const defaultLon = Number(widget.dataset.defaultLongitude ?? 0);
			const samePlace =
				Math.abs(defaultLat - location.latitude) < 0.01 &&
				Math.abs(defaultLon - location.longitude) < 0.01;
			if (samePlace) {
				return;
			}
			const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
			if (body) {
				renderWidgetEmpty(body);
			}
		});
	}
}

/* ------------------------------------------------------------------ */
/* Location picker modal                                               */
/* ------------------------------------------------------------------ */

let currentLocation: Location = DEFAULT_LOCATION;
let lastFocusedElement: HTMLElement | null = null;
let quickCities: Location[] = [];

function getModal(): HTMLElement | null {
	return $('#kk-weather-picker');
}

function setStatus(message: string): void {
	const status = $('[data-kk-weather-status]');
	if (status) {
		status.textContent = message;
	}
}

function updateCurrentLine(): void {
	const line = $('[data-kk-weather-current]');
	if (line) {
		line.textContent = `Current location: ${currentLocation.name} (${formatCoords(currentLocation)})`;
	}
}

function applyLocation(location: Location, announce = true): void {
	currentLocation = location;
	storeLocation(location);
	updateCurrentLine();
	syncCoordsInputs();
	if (announce) {
		setStatus(`Location set to ${location.name}.`);
	}
	void refreshWeatherDisplays(location);
}

function syncCoordsInputs(): void {
	const lat = $('[data-kk-weather-lat]') as HTMLInputElement | null;
	const lon = $('[data-kk-weather-lon]') as HTMLInputElement | null;
	if (lat) {
		lat.value = String(currentLocation.latitude);
	}
	if (lon) {
		lon.value = String(currentLocation.longitude);
	}
}

function openPicker(): void {
	const modal = getModal();
	if (!modal) {
		return;
	}
	lastFocusedElement = document.activeElement as HTMLElement | null;
	modal.hidden = false;
	// Lock the page behind the modal on both scroll roots: locking body
	// alone does not stop scrolling on every mobile browser, and a page
	// that still scrolls behind the overlay leaves sections reachable
	// below the fold.
	document.body.style.overflow = 'hidden';
	document.documentElement.style.overflow = 'hidden';
	updateCurrentLine();
	syncCoordsInputs();
	const search = $('[data-kk-weather-search]') as HTMLInputElement | null;
	if (search) {
		search.focus();
	}
}

function closePicker(): void {
	const modal = getModal();
	if (!modal || modal.hidden) {
		return;
	}
	modal.hidden = true;
	document.body.style.overflow = '';
	document.documentElement.style.overflow = '';
	if (lastFocusedElement) {
		lastFocusedElement.focus();
		lastFocusedElement = null;
	}
}

function trapFocus(event: KeyboardEvent): void {
	const modal = getModal();
	if (!modal || modal.hidden || event.key !== 'Tab') {
		return;
	}
	const focusable = $all<HTMLElement>(
		'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
		modal,
	).filter((el) => !el.hasAttribute('disabled') && el.offsetParent !== null);
	if (focusable.length === 0) {
		return;
	}
	const first = focusable[0];
	const last = focusable[focusable.length - 1];
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault();
		first.focus();
	}
}

/* Find the quick pick a location duplicates, if any. Defaults come
 * first, so a match against a built-in city wins over a user-added
 * copy of the same place. */
function findExistingPick(location: Location): Location | null {
	for (const city of [...quickCities, ...getCustomCities()]) {
		if (isSamePlace(city, location)) {
			return city;
		}
	}
	return null;
}

function renderStrip(): void {
	const strip = $('[data-kk-weather-strip]');
	if (!strip) {
		return;
	}
	strip.textContent = '';
	const customs = getCustomCities();
	const all = [...quickCities, ...customs];
	for (const city of all) {
		const isCustom = customs.includes(city);
		const item = document.createElement('li');
		const chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'kk-weather-chip';
		if (currentLocation.name === city.name) {
			chip.classList.add('is-active');
			chip.setAttribute('aria-pressed', 'true');
		} else {
			chip.setAttribute('aria-pressed', 'false');
		}
		chip.textContent = city.name;
		chip.addEventListener('click', () => {
			applyLocation({ ...city, source: isCustom ? 'search' : 'quick' });
			renderStrip();
		});
		item.appendChild(chip);
		if (isCustom) {
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'kk-weather-chip-remove';
			remove.setAttribute('aria-label', `Remove ${city.name} from quick picks`);
			remove.textContent = '×';
			remove.addEventListener('click', (event) => {
				event.stopPropagation();
				storeCustomCities(getCustomCities().filter((c) => c.name !== city.name));
				renderStrip();
			});
			item.appendChild(remove);
		}
		strip.appendChild(item);
	}
}

function initStrip(): void {
	// Capture the server-rendered quick picks once, then own the strip.
	quickCities = $all<HTMLButtonElement>('[data-kk-weather-chip]').map((chip) => ({
		name: chip.dataset.name ?? '',
		latitude: Number(chip.dataset.latitude ?? 0),
		longitude: Number(chip.dataset.longitude ?? 0),
		source: 'quick' as const,
	}));
	renderStrip();

	$('[data-kk-weather-add]')?.addEventListener('click', () => {
		// Dedupe against BOTH lists: the built-in quick picks and the
		// user-added ones. Adding a city that is already a default
		// (e.g. Medan) used to push a second identical chip into the
		// strip; now the existing chip is selected instead.
		const existing = findExistingPick(currentLocation);
		if (existing) {
			applyLocation({ ...existing, source: existing.source });
			renderStrip();
			setStatus(`${existing.name} is already in your quick picks.`);
			return;
		}
		const customs = getCustomCities();
		if (customs.length >= MAX_CUSTOM_CITIES) {
			setStatus('Quick picks is full. Remove one first.');
			return;
		}
		customs.push({ ...currentLocation, source: 'search' });
		storeCustomCities(customs);
		renderStrip();
		setStatus(`${currentLocation.name} added to quick picks.`);
	});
}

function initSearch(): void {
	const input = $('[data-kk-weather-search]') as HTMLInputElement | null;
	const results = $('[data-kk-weather-results]');
	if (!input || !results) {
		return;
	}
	let timer: number | null = null;
	let controller: AbortController | null = null;

	// The search field owns Escape: clear the query and leave, keep the
	// picker open. A second Escape (focus outside the field) closes it.
	input.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') {
			return;
		}
		event.stopPropagation();
		input.value = '';
		results.textContent = '';
		setStatus('');
		input.blur();
	});

	input.addEventListener('input', () => {
		if (timer !== null) {
			window.clearTimeout(timer);
		}
		const query = input.value.trim();
		results.textContent = '';
		if (query.length < 2) {
			setStatus('');
			return;
		}
		setStatus('Searching…');
		timer = window.setTimeout(() => {
			controller?.abort();
			controller = new AbortController();
			searchPlaces(query, controller.signal)
				.then((places) => {
					results.textContent = '';
					if (places.length === 0) {
						setStatus('No places found. Try another name.');
						return;
					}
					setStatus('');
					for (const place of places) {
						const item = document.createElement('li');
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'kk-weather-result';
						button.textContent =
							place.country && place.country !== place.name
								? `${place.name}, ${place.country}`
								: place.name;
						button.addEventListener('click', () => {
							applyLocation({
								name: place.name,
								latitude: place.latitude,
								longitude: place.longitude,
								source: 'search',
							});
							results.textContent = '';
							input.value = place.name;
							renderStrip();
						});
						item.appendChild(button);
						results.appendChild(item);
					}
				})
				.catch((error: unknown) => {
					if (error instanceof DOMException && error.name === 'AbortError') {
						return;
					}
					results.textContent = '';
					setStatus('Search is unavailable right now. Try coordinates instead.');
				});
		}, 300);
	});
}

function initCoords(): void {
	const applyButton = $('[data-kk-weather-apply-coords]');
	const errorEl = $('[data-kk-weather-coords-error]');
	if (!applyButton) {
		return;
	}
	applyButton.addEventListener('click', () => {
		const latInput = $('[data-kk-weather-lat]') as HTMLInputElement | null;
		const lonInput = $('[data-kk-weather-lon]') as HTMLInputElement | null;
		if (!latInput || !lonInput) {
			return;
		}
		const latitude = Number(latInput.value);
		const longitude = Number(lonInput.value);
		const valid =
			latInput.value.trim() !== '' &&
			lonInput.value.trim() !== '' &&
			Number.isFinite(latitude) &&
			Number.isFinite(longitude) &&
			latitude >= -90 &&
			latitude <= 90 &&
			longitude >= -180 &&
			longitude <= 180;
		latInput.setAttribute('aria-invalid', valid ? 'false' : 'true');
		lonInput.setAttribute('aria-invalid', valid ? 'false' : 'true');
		if (!valid) {
			if (errorEl) {
				errorEl.textContent = 'Enter a latitude between -90 and 90 and a longitude between -180 and 180.';
				errorEl.hidden = false;
			}
			return;
		}
		if (errorEl) {
			errorEl.textContent = '';
			errorEl.hidden = true;
		}
		// Typed coordinates have no name of their own: resolve the area
		// first so the top bar reads e.g. "Kabupaten Malang" instead of
		// the anonymous "Custom location".
		void (async () => {
			const name = await reverseGeocode(latitude, longitude);
			applyLocation({ name: name ?? 'Custom location', latitude, longitude, source: 'coords' });
			renderStrip();
		})();
	});
}

function initGeolocate(): void {
	const button = $('[data-kk-weather-geolocate]');
	if (!button) {
		return;
	}
	button.addEventListener('click', () => {
		if (!('geolocation' in navigator)) {
			setStatus('Geolocation is not supported by this browser. Search for a city or enter coordinates.');
			return;
		}
		setStatus('Asking for your location…');
		navigator.geolocation.getCurrentPosition(
			(position) => {
				void (async () => {
					const resolved: Location = {
						name: 'My location',
						latitude: position.coords.latitude,
						longitude: position.coords.longitude,
						source: 'geolocation',
					};
					// A GPS fix at a city that is already a quick pick is
					// that city: select its chip instead of leaving an
					// anonymous "My location" that duplicates it on Add.
					const existing = findExistingPick(resolved);
					if (existing) {
						applyLocation({ ...existing, source: existing.source }, false);
						setStatus(`You're near ${existing.name} — showing its weather.`);
					} else {
						// Otherwise resolve the fix to its area name so
						// the top bar reads "Kabupaten Malang", not
						// "My location".
						const name = await reverseGeocode(resolved.latitude, resolved.longitude);
						applyLocation({ ...resolved, name: name ?? 'My location' });
					}
					renderStrip();
				})();
			},
			(error) => {
				if (error.code === error.PERMISSION_DENIED) {
					setStatus('Location permission was denied. You can still search for a city or enter coordinates.');
				} else if (error.code === error.TIMEOUT) {
					setStatus('Finding your location timed out. Try again, or search for a city.');
				} else {
					setStatus('Your location could not be determined. Search for a city or enter coordinates.');
				}
			},
			{ timeout: 10000, maximumAge: 60000 },
		);
	});
}

function isEditableElement(target: EventTarget | null): boolean {
	if (!(target instanceof HTMLElement)) {
		return false;
	}
	if (target.isContentEditable) {
		return true;
	}
	const tag = target.tagName;
	return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
}

function initPicker(): void {
	const modal = getModal();
	if (!modal) {
		return;
	}
	$all('[data-kk-weather-open]').forEach((opener) => {
		opener.addEventListener('click', openPicker);
	});
	$all('[data-kk-weather-close]', modal).forEach((closer) => {
		closer.addEventListener('click', closePicker);
	});
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !modal.hidden) {
			if (isEditableElement(event.target) && modal.contains(event.target as Node)) {
				// The field owns Escape: leave it and keep the picker
				// open. A second Escape (focus outside any field) closes.
				(event.target as HTMLElement).blur();
				return;
			}
			closePicker();
		}
		if (event.key === 'Tab') {
			trapFocus(event);
		}
	});
	$('[data-kk-weather-reset]')?.addEventListener('click', () => {
		storeCustomCities([]);
		applyLocation(DEFAULT_LOCATION);
		renderStrip();
		setStatus('Location reset to Padang.');
	});
	initStrip();
	initSearch();
	initCoords();
	initGeolocate();
}

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', () => {
	const data = getKkData();
	if (data.isWeatherEnabled === false) {
		return;
	}
	currentLocation = getStoredLocation() ?? DEFAULT_LOCATION;
	// A stored "My location" / "Custom location" predates area names:
	// resolve it once in the background so the top bar shows the area
	// instead of the generic label. The first paint does not wait for
	// it — the name updates in place when the lookup returns.
	if (currentLocation.name === 'My location' || currentLocation.name === 'Custom location') {
		const stored = currentLocation;
		void reverseGeocode(stored.latitude, stored.longitude).then((name) => {
			if (!name) {
				return;
			}
			currentLocation = { ...stored, name };
			storeLocation(currentLocation);
			updateCurrentLine();
			void refreshWeatherDisplays(currentLocation);
			renderStrip();
		});
	}
	const topbar = $('#kk-topbar-weather');
	if (topbar) {
		// Paint instantly from the browser cache when it is fresh, or
		// hold the slot with a loading state so the top bar does not
		// bounce — then always revalidate in the background so the
		// numbers stay current.
		const cached = getCachedWeather(currentLocation);
		if (cached) {
			renderWeatherDisplays(cached);
		} else {
			renderTopbarLoading(currentLocation);
		}
		void refreshWeatherDisplays(currentLocation);
	} else {
		void refreshWidgetsFor(currentLocation);
	}
	initPicker();
});
