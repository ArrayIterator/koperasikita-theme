/**
 * Koperasi Kita - weather module (standalone).
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

import * as L from 'leaflet';

/* Local AJAX client (self-contained on purpose).
 *
 * Entries load as type="module" (see Enqueue), so npm imports such as
 * Leaflet below resolve through Vite's shared chunks. The ~20-line
 * envelope client still lives here instead of in the shared chunk:
 * keep it in sync with src/ts/api.ts if the envelope ever changes. */

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
	// Temperature unit (Customizer: kk_weather_unit). The API always
	// returns Celsius; Fahrenheit converts on display.
	weatherUnit?: string;
}

// No global augmentation here: src/ts/main.ts already declares
// window.kkData, and a second declaration with a different shape breaks
// the build. Read it through a narrow cast instead.

/* Temperature unit: the API returns Celsius, and Fahrenheit is a
 * display conversion (degF = degC x 9/5 + 32). */
function isFahrenheit(): boolean {
	const data = window.kkData as unknown as KkData | undefined;
	return data?.weatherUnit === 'fahrenheit';
}

function formatTemp(celsius: number): string {
	const value = isFahrenheit()
		? Math.round((celsius * 9) / 5 + 32)
		: Math.round(celsius);
	return `${value}°${isFahrenheit() ? 'F' : 'C'}`;
}

interface Location {
	name: string;
	latitude: number;
	longitude: number;
	source: 'default' | 'quick' | 'search' | 'coords' | 'geolocation' | 'map';
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

/* One location store per surface: the top bar keeps its own key
 * and every weather widget keeps one derived from its widget id, so
 * changing the location on one surface never moves the other. The
 * top bar's key is `topbar.loc`; a widget's key is
 * `kk-weather-location-<widget id>`. The quick-pick list stays
 * shared: it belongs to the one location picker modal, not to a
 * surface. */
const TOPBAR_LOCATION_KEY = 'topbar.loc';
const TOPBAR_DATA_KEY = 'kk-weather-data-topbar';
const CUSTOM_CITIES_KEY = 'kk-weather-cities';
const MAX_CUSTOM_CITIES = 8;

function widgetLocationKey(widgetKey: string): string {
	return `kk-weather-location-${widgetKey}`;
}

function widgetDataKey(widgetKey: string): string {
	return `kk-weather-data-${widgetKey}`;
}

// Client-side weather cache: the last fetched conditions, kept in
// localStorage so a surface can paint instantly on the next visit
// instead of waiting for a round-trip. Namespaced per surface, like
// the location keys, so one surface never serves another's weather.
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

function getCachedWeather(cacheKey: string, location: Location): WeatherData | null {
	if (getCacheLifetimeMs() === 0) {
		return null;
	}
	try {
		const raw = window.localStorage.getItem(cacheKey);
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

function setCachedWeather(cacheKey: string, location: Location, weather: WeatherData): void {
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
		window.localStorage.setItem(cacheKey, JSON.stringify(cached));
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

/* Stored location                                                     */

function getStoredLocation(storageKey: string): Location | null {
	try {
		const raw = window.localStorage.getItem(storageKey);
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

function storeLocation(location: Location, storageKey: string): void {
	try {
		window.localStorage.setItem(storageKey, JSON.stringify(location));
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

/* Server API                                                          */

async function fetchWeather(cacheKey: string, location: Location): Promise<WeatherData> {
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
	setCachedWeather(cacheKey, location, result.weather);
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
 * cannot be resolved - the caller keeps the generic label then. */
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

/* Rendering                                                           */

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
	temp.textContent = formatTemp(weather.temperature);
	const place = document.createElement('span');
	place.className = 'kk-topbar-weather-place';
	place.textContent = weather.name;
	slot.append(temp, document.createTextNode(' '), place);
	slot.setAttribute('title', weather.condition);
}

// Loading state for the top bar, shown while the first fetch runs and
// there is no cached weather to paint. It carries the place name
// (known immediately from the stored location) plus a "--" temperature,
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
	temp.textContent = '--';
	const place = document.createElement('span');
	place.className = 'kk-topbar-weather-place';
	place.textContent = location.name;
	slot.append(temp, document.createTextNode(' '), place);
	slot.setAttribute('title', 'Loading weather');
}

// Paint one widget from one weather object.
function renderWidget(widget: HTMLElement, weather: WeatherData): void {
	const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
	const place = widget.querySelector<HTMLElement>('.kk-weather-place');
	if (place) {
		place.textContent = weather.name;
	}
	if (body) {
		renderWidgetBody(body, weather, widget.dataset.showDetails === '1', widget.dataset.showIcon !== '0');
	}
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

function renderWidgetBody(body: HTMLElement, weather: WeatherData, showDetails: boolean, showIcon: boolean): void {
	body.textContent = '';
	// The condition icon rides in the response as the theme's own icon
	// SVG (trusted markup from the server, no user input in it) - the
	// same shape the server-side first paint renders.
	const current = document.createElement('div');
	current.className = 'kk-weather-now';
	if (weather.icon && showIcon) {
		const icon = document.createElement('span');
		icon.className = 'kk-weather-icon';
		icon.setAttribute('aria-hidden', 'true');
		icon.innerHTML = weather.icon;
		current.appendChild(icon);
	}
	const temp = document.createElement('p');
	temp.className = 'kk-weather-temp';
	temp.textContent = formatTemp(weather.temperature);
	current.appendChild(temp);
	body.appendChild(current);
	const condition = document.createElement('p');
	condition.className = 'kk-weather-condition';
	condition.textContent = weather.condition;
	body.appendChild(condition);

	if (!showDetails) {
		return;
	}
	const rows: Array<[string, string]> = [];
	if (weather.feels_like !== null) {
		rows.push(['Feels like', formatTemp(weather.feels_like)]);
	}
	if (weather.high !== null && weather.low !== null) {
		rows.push(['High / Low', `${formatTemp(weather.high)} / ${formatTemp(weather.low)}`]);
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

async function refreshTopbar(location: Location): Promise<void> {
	try {
		const weather = await fetchWeather(TOPBAR_DATA_KEY, location);
		renderTopbar(weather);
	} catch {
		// A failed revalidation keeps a cached display (it is still
		// fresh); only a page with no data at all - still showing the
		// loading state - hides the slot.
		const slot = $('#kk-topbar-weather');
		if (slot && slot.querySelector('.kk-topbar-weather-loading')) {
			hideTopbar();
		}
	}
}

async function refreshWidget(widget: HTMLElement, location: Location): Promise<void> {
	try {
		const weather = await fetchWeather(widgetDataKey(widgetKey(widget)), location);
		renderWidget(widget, weather);
	} catch {
		// Keep the server-side first paint when it already shows the
		// widget's location; only a stale paint gets the honest empty.
		const def = widgetDefaultLocation(widget);
		const samePlace =
			Math.abs(def.latitude - location.latitude) < 0.01 &&
			Math.abs(def.longitude - location.longitude) < 0.01;
		if (samePlace) {
			return;
		}
		const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
		if (body) {
			renderWidgetEmpty(body);
		}
	}
}

/* Location picker modal                                               */

/* One location per surface. The top bar keeps its own; every widget
 * keeps one keyed by its widget id. The picker edits exactly one
 * surface at a time: whichever opener was clicked sets the target,
 * and only that surface is stored and re-rendered. */
type PickerTarget =
	| { kind: 'topbar' }
	| { kind: 'widget'; element: HTMLElement; key: string };

let topbarLocation: Location = DEFAULT_LOCATION;
const widgetLocations = new Map<string, Location>();
let pickerTarget: PickerTarget = { kind: 'topbar' };
let lastFocusedElement: HTMLElement | null = null;
let quickCities: Location[] = [];
let weatherMap: L.Map | null = null;
let mapMarker: L.Marker | null = null;
let mapNameEdited = false;

// Resolved per-widget key, stamped at boot from the server-rendered
// data-weather-key (the widget id for classic widgets, a render-order
// ordinal for blocks). Falls back to the element's position among the
// widgets on the page, so stale markup without the attribute still
// gives every widget its own key.
const widgetKeys = new WeakMap<HTMLElement, string>();

function widgetKey(widget: HTMLElement): string {
	const keyed = widgetKeys.get(widget);
	if (keyed !== undefined) {
		return keyed;
	}
	const widgets = $all<HTMLElement>('[data-kk-weather-widget]');
	const key = `block-${Math.max(0, widgets.indexOf(widget))}`;
	widgetKeys.set(widget, key);
	return key;
}

function widgetDefaultLocation(widget: HTMLElement): Location {
	const latitude = Number(widget.dataset.defaultLatitude ?? Number.NaN);
	const longitude = Number(widget.dataset.defaultLongitude ?? Number.NaN);
	return {
		name: widget.dataset.defaultCity ?? DEFAULT_LOCATION.name,
		latitude: Number.isFinite(latitude) ? latitude : DEFAULT_LOCATION.latitude,
		longitude: Number.isFinite(longitude) ? longitude : DEFAULT_LOCATION.longitude,
		source: 'default',
	};
}

function targetStorageKey(target: PickerTarget): string {
	return target.kind === 'topbar' ? TOPBAR_LOCATION_KEY : widgetLocationKey(target.key);
}

function getTargetLocation(target: PickerTarget): Location {
	if (target.kind === 'topbar') {
		return topbarLocation;
	}
	return widgetLocations.get(target.key) ?? widgetDefaultLocation(target.element);
}

function setTargetLocation(target: PickerTarget, location: Location): void {
	if (target.kind === 'topbar') {
		topbarLocation = location;
	} else {
		widgetLocations.set(target.key, location);
	}
	storeLocation(location, targetStorageKey(target));
}

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
		const location = getTargetLocation(pickerTarget);
		line.textContent = `Current location: ${location.name} (${formatCoords(location)})`;
	}
}

function applyLocation(location: Location, target: PickerTarget, announce = true): void {
	setTargetLocation(target, location);
	updateCurrentLine();
	syncCoordsInputs();
	if (announce) {
		setStatus(`Location set to ${location.name}.`);
	}
	if (target.kind === 'topbar') {
		void refreshTopbar(location);
	} else {
		void refreshWidget(target.element, location);
	}
	syncMapToTarget(target);
}

function syncCoordsInputs(): void {
	const lat = $('[data-kk-weather-lat]') as HTMLInputElement | null;
	const lon = $('[data-kk-weather-lon]') as HTMLInputElement | null;
	const location = getTargetLocation(pickerTarget);
	if (lat) {
		lat.value = String(location.latitude);
	}
	if (lon) {
		lon.value = String(location.longitude);
	}
}

/* Map picker                                                           */

/* OpenStreetMap tiles, same source the agenda maps use. */
const MAP_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
const MAP_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';
const MAP_ZOOM = 12;

/** The KK-green map pin: a CSS teardrop, no marker-image assets - the
 * same pin the agenda maps use, under the weather class names. */
function makeWeatherPin(): L.DivIcon {
	return L.divIcon({
		className: 'kk-weather-pin-icon',
		html: '<span class="kk-weather-pin"></span>',
		iconSize: [28, 28],
		iconAnchor: [14, 34],
	});
}

interface MapElements {
	map: HTMLElement;
	name: HTMLInputElement;
	coords: HTMLElement;
	save: HTMLButtonElement;
	cancel: HTMLButtonElement;
}

function mapEls(): MapElements | null {
	const map = $('[data-kk-weather-map]');
	const name = $('[data-kk-weather-map-name]') as HTMLInputElement | null;
	const coords = $('[data-kk-weather-map-coords]');
	const save = $('[data-kk-weather-map-save]') as HTMLButtonElement | null;
	const cancel = $('[data-kk-weather-map-cancel]') as HTMLButtonElement | null;
	if (!map || !name || !coords || !save || !cancel) {
		return null;
	}
	return { map, name, coords, save, cancel };
}

/* Move the marker to a place: the coordinates line always shows where
 * the marker sits, so the lat/lon the visitor saves come from the
 * marker, never from a typed field. */
function setMapPin(latitude: number, longitude: number, name?: string): void {
	const els = mapEls();
	if (!els) {
		return;
	}
	els.coords.textContent = `${latitude.toFixed(4)}, ${longitude.toFixed(4)}`;
	if (name !== undefined) {
		els.name.value = name;
		mapNameEdited = false;
	}
	if (weatherMap === null) {
		return;
	}
	if (mapMarker === null) {
		mapMarker = L.marker([latitude, longitude], { icon: makeWeatherPin(), draggable: true }).addTo(weatherMap);
		mapMarker.on('dragend', () => {
			const pos = mapMarker?.getLatLng();
			if (pos) {
				void placeMapPin(pos.lat, pos.lng);
			}
		});
	} else {
		mapMarker.setLatLng([latitude, longitude]);
	}
	weatherMap.setView([latitude, longitude], weatherMap.getZoom());
}

/* Place the marker by hand (map click or marker drag): the name field
 * follows with the area name, unless the visitor already typed a name
 * of their own - a typed name is never overwritten. */
async function placeMapPin(latitude: number, longitude: number): Promise<void> {
	setMapPin(latitude, longitude);
	if (mapNameEdited) {
		return;
	}
	const name = await reverseGeocode(latitude, longitude);
	if (!name || mapNameEdited) {
		return;
	}
	// The marker may have moved again while the lookup ran.
	const pos = mapMarker?.getLatLng();
	if (!pos || Math.abs(pos.lat - latitude) > 0.0001 || Math.abs(pos.lng - longitude) > 0.0001) {
		return;
	}
	const els = mapEls();
	if (els) {
		els.name.value = name;
	}
}

/* Stage the map for the surface being edited: the marker starts on the
 * surface's current location, with its name in the field. The map is
 * built lazily on the first open - Leaflet needs a laid-out box, and
 * the modal is hidden until then. */
function stageMapForTarget(): void {
	const els = mapEls();
	if (!els) {
		return;
	}
	const location = getTargetLocation(pickerTarget);
	if (weatherMap === null) {
		const map = L.map(els.map, { scrollWheelZoom: false }).setView([location.latitude, location.longitude], MAP_ZOOM);
		L.tileLayer(MAP_TILE_URL, { attribution: MAP_ATTRIBUTION, maxZoom: 19 }).addTo(map);
		map.on('click', (event: L.LeafletMouseEvent) => {
			void placeMapPin(event.latlng.lat, event.latlng.lng);
		});
		weatherMap = map;
		setMapPin(location.latitude, location.longitude, location.name);
		requestAnimationFrame(() => map.invalidateSize());
		return;
	}
	setMapPin(location.latitude, location.longitude, location.name);
	requestAnimationFrame(() => weatherMap?.invalidateSize());
}

/* After any committed pick (quick pick, coordinates, geolocation,
 * reset), the map follows the surface so it never shows a stale pin. */
function syncMapToTarget(target: PickerTarget): void {
	if (weatherMap === null) {
		return;
	}
	const location = getTargetLocation(target);
	setMapPin(location.latitude, location.longitude, location.name);
}

function initMap(): void {
	const els = mapEls();
	if (!els) {
		return;
	}
	els.name.addEventListener('input', () => {
		mapNameEdited = true;
	});
	els.save.addEventListener('click', () => {
		const pos = mapMarker?.getLatLng();
		if (!pos) {
			return;
		}
		// An unnamed pin takes the area name when the lookup knows it,
		// and stays an anonymous custom location when it does not.
		void (async () => {
			const typed = els.name.value.trim();
			const resolved = typed !== '' ? typed : (await reverseGeocode(pos.lat, pos.lng)) ?? 'Custom location';
			applyLocation({ name: resolved, latitude: pos.lat, longitude: pos.lng, source: 'map' }, pickerTarget);
			closePicker();
		})();
	});
	els.cancel.addEventListener('click', () => {
		closePicker();
	});
}

function openPicker(opener: HTMLElement): void {
	const modal = getModal();
	if (!modal) {
		return;
	}
	// The surface being edited is the one the opener belongs to: a
	// button inside a widget edits that widget, the top-bar button
	// edits the top bar.
	const widgetEl = opener.closest('[data-kk-weather-widget]') as HTMLElement | null;
	pickerTarget = widgetEl
		? { kind: 'widget', element: widgetEl, key: widgetKey(widgetEl) }
		: { kind: 'topbar' };
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
	renderStrip();
	stageMapForTarget();
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
	const current = getTargetLocation(pickerTarget);
	for (const city of all) {
		const isCustom = customs.includes(city);
		const item = document.createElement('li');
		const chip = document.createElement('button');
		chip.type = 'button';
		chip.className = 'kk-weather-chip';
		if (current.name === city.name) {
			chip.classList.add('is-active');
			chip.setAttribute('aria-pressed', 'true');
		} else {
			chip.setAttribute('aria-pressed', 'false');
		}
		chip.textContent = city.name;
		chip.addEventListener('click', () => {
			applyLocation({ ...city, source: isCustom ? 'search' : 'quick' }, pickerTarget);
			renderStrip();
		});
		item.appendChild(chip);
		if (isCustom) {
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'kk-weather-chip-remove';
			remove.setAttribute('aria-label', `Remove ${city.name} from quick picks`);
			remove.textContent = 'x';
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
	// Widget-configured locations join the quick picks, so a visitor
	// can switch between the cities the widget was set up with. The
	// built-in quick picks win a duplicate (they come first).
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		const raw = widget.dataset.locations ?? '';
		if (raw === '') {
			return;
		}
		try {
			const parsed: unknown = JSON.parse(raw);
			if (!Array.isArray(parsed)) {
				return;
			}
			for (const item of parsed) {
				const candidate = item as Partial<Location>;
				if (
					typeof candidate.name !== 'string' ||
					typeof candidate.latitude !== 'number' ||
					typeof candidate.longitude !== 'number'
				) {
					continue;
				}
				const location: Location = {
					name: candidate.name,
					latitude: candidate.latitude,
					longitude: candidate.longitude,
					source: 'quick',
				};
				if (!quickCities.some((kept) => isSamePlace(kept, location))) {
					quickCities.push(location);
				}
			}
		} catch {
			// Malformed data-locations: the widget still renders, the
			// strip just keeps the built-in quick picks.
		}
	});
	renderStrip();

	$('[data-kk-weather-add]')?.addEventListener('click', () => {
		// Dedupe against BOTH lists: the built-in quick picks and the
		// user-added ones. Adding a city that is already a default
		// (e.g. Medan) used to push a second identical chip into the
		// strip; now the existing chip is selected instead.
		const current = getTargetLocation(pickerTarget);
		const existing = findExistingPick(current);
		if (existing) {
			applyLocation({ ...existing, source: existing.source }, pickerTarget);
			renderStrip();
			setStatus(`${existing.name} is already in your quick picks.`);
			return;
		}
		const customs = getCustomCities();
		if (customs.length >= MAX_CUSTOM_CITIES) {
			setStatus('Quick picks is full. Remove one first.');
			return;
		}
		customs.push({ ...current, source: 'search' });
		storeCustomCities(customs);
		renderStrip();
		setStatus(`${current.name} added to quick picks.`);
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
		setStatus('Searching...');
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
							// Search is a helper: it moves the map and the
							// marker to the found place. Nothing is applied
							// until the visitor confirms on the map. Without
							// the map section (stale cached markup), the
							// search applies directly, as it used to.
							if (mapEls() === null) {
								applyLocation(
									{
										name: place.name,
										latitude: place.latitude,
										longitude: place.longitude,
										source: 'search',
									},
									pickerTarget,
								);
								results.textContent = '';
								input.value = place.name;
								renderStrip();
								return;
							}
							setMapPin(place.latitude, place.longitude, place.name);
							results.textContent = '';
							input.value = place.name;
							setStatus(`Moved the map to ${place.name}. Press "Use this location" to apply it.`);
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
			applyLocation({ name: name ?? 'Custom location', latitude, longitude, source: 'coords' }, pickerTarget);
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
		setStatus('Asking for your location...');
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
						applyLocation({ ...existing, source: existing.source }, pickerTarget, false);
						setStatus(`You're near ${existing.name} - showing its weather.`);
					} else {
						// Otherwise resolve the fix to its area name so
						// the top bar reads "Kabupaten Malang", not
						// "My location".
						const name = await reverseGeocode(resolved.latitude, resolved.longitude);
						applyLocation({ ...resolved, name: name ?? 'My location' }, pickerTarget);
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
	$all<HTMLElement>('[data-kk-weather-open]').forEach((opener) => {
		opener.addEventListener('click', () => openPicker(opener));
	});
	$all<HTMLElement>('[data-kk-weather-close]', modal).forEach((closer) => {
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
		const def =
			pickerTarget.kind === 'topbar'
				? DEFAULT_LOCATION
				: widgetDefaultLocation(pickerTarget.element);
		applyLocation(def, pickerTarget);
		renderStrip();
		setStatus(`Location reset to ${def.name}.`);
	});
	initMap();
	initStrip();
	initSearch();
	initCoords();
	initGeolocate();
}

/* Boot                                                                */

// A stored "My location" / "Custom location" predates area names:
// resolve it once in the background so the surface shows the area
// instead of the generic label. The first paint does not wait for
// it - the name updates in place when the lookup returns.
function upgradeGenericName(target: PickerTarget): void {
	const stored = getTargetLocation(target);
	if (stored.name !== 'My location' && stored.name !== 'Custom location') {
		return;
	}
	void reverseGeocode(stored.latitude, stored.longitude).then((name) => {
		if (!name) {
			return;
		}
		// Skip when the visitor already picked another place meanwhile.
		if (getTargetLocation(target) !== stored) {
			return;
		}
		const next = { ...stored, name };
		setTargetLocation(target, next);
		updateCurrentLine();
		if (target.kind === 'topbar') {
			void refreshTopbar(next);
		} else {
			void refreshWidget(target.element, next);
		}
		renderStrip();
	});
}

document.addEventListener('DOMContentLoaded', () => {
	const data = getKkData();
	if (data.isWeatherEnabled === false) {
		return;
	}
	// Stamp every widget's storage key first: the picker target and
	// the per-widget locations both read from it.
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget, index) => {
		const key =
			widget.dataset.weatherKey !== undefined && widget.dataset.weatherKey !== ''
				? widget.dataset.weatherKey
				: `block-${index}`;
		widgetKeys.set(widget, key);
		widgetLocations.set(key, getStoredLocation(widgetLocationKey(key)) ?? widgetDefaultLocation(widget));
	});
	topbarLocation = getStoredLocation(TOPBAR_LOCATION_KEY) ?? DEFAULT_LOCATION;
	upgradeGenericName({ kind: 'topbar' });
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		upgradeGenericName({ kind: 'widget', element: widget, key: widgetKey(widget) });
	});
	const topbar = $('#kk-topbar-weather');
	if (topbar) {
		// Paint instantly from the browser cache when it is fresh, or
		// hold the slot with a loading state so the top bar does not
		// bounce - then always revalidate in the background so the
		// numbers stay current.
		const cached = getCachedWeather(TOPBAR_DATA_KEY, topbarLocation);
		if (cached) {
			renderTopbar(cached);
		} else {
			renderTopbarLoading(topbarLocation);
		}
		void refreshTopbar(topbarLocation);
	}
	// Every widget revalidates for its own location; a widget without
	// a stored location keeps its server-side first paint on failure.
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		void refreshWidget(widget, getTargetLocation({ kind: 'widget', element: widget, key: widgetKey(widget) }));
	});
	initPicker();
});
