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

interface KkData {
	ajaxUrl?: string;
	nonce?: string;
	isWeatherEnabled?: boolean;
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
		return parsed.filter(
			(item): item is Location =>
				typeof item === 'object' &&
				item !== null &&
				typeof (item as Location).name === 'string' &&
				typeof (item as Location).latitude === 'number' &&
				typeof (item as Location).longitude === 'number',
		);
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

async function postAjax(action: string, params: Record<string, string>): Promise<unknown> {
	const data = getKkData();
	if (!data.ajaxUrl || !data.nonce) {
		throw new Error('Weather endpoint is not available.');
	}
	const body = new URLSearchParams({ action, nonce: data.nonce, ...params });
	const response = await fetch(data.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
	});
	if (!response.ok) {
		throw new Error(`Weather request failed (${response.status}).`);
	}
	return response.json() as Promise<unknown>;
}

async function fetchWeather(location: Location): Promise<WeatherData> {
	const json = (await postAjax('kk_weather', {
		name: location.name,
		latitude: String(location.latitude),
		longitude: String(location.longitude),
	})) as { success?: boolean; data?: { weather?: WeatherData } };
	if (!json.success || !json.data?.weather) {
		throw new Error('Weather is unavailable right now.');
	}
	return json.data.weather;
}

async function searchPlaces(query: string, signal: AbortSignal): Promise<SearchResult[]> {
	const data = getKkData();
	if (!data.ajaxUrl || !data.nonce) {
		throw new Error('Search endpoint is not available.');
	}
	const body = new URLSearchParams({ action: 'kk_weather_search', nonce: data.nonce, query });
	const response = await fetch(data.ajaxUrl, {
		method: 'POST',
		credentials: 'same-origin',
		body,
		signal,
	});
	if (!response.ok) {
		throw new Error(`Search request failed (${response.status}).`);
	}
	const json = (await response.json()) as { success?: boolean; data?: { results?: SearchResult[] } };
	if (!json.success || !Array.isArray(json.data?.results)) {
		return [];
	}
	return json.data.results;
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
	const temp = document.createElement('span');
	temp.className = 'kk-topbar-weather-temp';
	temp.textContent = `${Math.round(weather.temperature)}°`;
	const place = document.createElement('span');
	place.className = 'kk-topbar-weather-place';
	place.textContent = weather.name;
	slot.append(temp, document.createTextNode(' '), place);
	slot.setAttribute('title', weather.condition);
}

function hideTopbar(): void {
	const slot = $('#kk-topbar-weather');
	if (slot) {
		// Empty slot: the theme CSS hides it entirely, so a failed fetch
		// leaves no gap and no fake numbers behind.
		slot.textContent = '';
		slot.removeAttribute('title');
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
	} catch {
		hideTopbar();
		// Widgets keep their server-side first paint on failure: it shows
		// the widget's default city, labelled as such, so nothing is faked.
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
	document.body.style.overflow = 'hidden';
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
		const customs = getCustomCities();
		if (customs.some((c) => c.name === currentLocation.name)) {
			setStatus(`${currentLocation.name} is already in your quick picks.`);
			return;
		}
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
		applyLocation({ name: 'Custom location', latitude, longitude, source: 'coords' });
		renderStrip();
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
				applyLocation({
					name: 'My location',
					latitude: position.coords.latitude,
					longitude: position.coords.longitude,
					source: 'geolocation',
				});
				renderStrip();
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
	const topbar = $('#kk-topbar-weather');
	if (topbar) {
		void refreshWeatherDisplays(currentLocation);
	} else {
		void refreshWidgetsFor(currentLocation);
	}
	initPicker();
});
