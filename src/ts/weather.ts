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
	// User-facing picker strings, translated server-side
	// (wp_localize_script) and keyed by the English source text, so
	// the status lines and labels follow the site language instead of
	// staying hardcoded English. Missing keys fall back to English.
	weatherStrings?: Record<string, string>;
}

// No global augmentation here: src/ts/main.ts already declares
// window.kkData, and a second declaration with a different shape breaks
// the build. Read it through a narrow cast instead.

type TempUnit = 'celsius' | 'fahrenheit' | 'kelvin';

/* Temperature unit: the API returns Celsius, and Fahrenheit and
 * Kelvin are display conversions (degF = degC x 9/5 + 32,
 * K = degC + 273.15). A widget carries its own resolved unit in
 * data-temp-unit; without one the site-wide unit applies. */
function isFahrenheit(): boolean {
	const data = window.kkData as unknown as KkData | undefined;
	return data?.weatherUnit === 'fahrenheit';
}

function formatTemp(celsius: number, unit?: TempUnit): string {
	const resolved: TempUnit = unit ?? (isFahrenheit() ? 'fahrenheit' : 'celsius');
	if (resolved === 'kelvin') {
		return `${Math.round(celsius + 273.15)}K`;
	}
	if (resolved === 'fahrenheit') {
		return `${Math.round((celsius * 9) / 5 + 32)}°F`;
	}
	return `${Math.round(celsius)}°C`;
}

/* A widget's resolved temperature unit: the data-temp-unit the
 * server rendered, or the site-wide unit for a widget saved before
 * the per-widget setting existed. */
function widgetTempUnit(widget: HTMLElement): TempUnit {
	const unit = widget.dataset.tempUnit;
	if (unit === 'celsius' || unit === 'fahrenheit' || unit === 'kelvin') {
		return unit;
	}
	return isFahrenheit() ? 'fahrenheit' : 'celsius';
}

interface Location {
	name: string;
	latitude: number;
	longitude: number;
	source: 'default' | 'quick' | 'search' | 'coords' | 'geolocation' | 'map';
	// Full hierarchy ("Kelurahan, Kecamatan, Kota/Kab") when the
	// location came from reverse geocoding; the name is the short
	// display ("Kelurahan, Kota/Kab"). The full form rides in the
	// title attribute of the top-bar and widget place elements.
	fullName?: string;
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
	// Full hierarchy ("Kelurahan, Kecamatan, Kota/Kab") for the title
	// attribute; the name is the short display. Attached by the client
	// from the picked location - the cached server response omits it.
	fullName?: string;
}

interface SearchResult {
	name: string;
	latitude: number;
	longitude: number;
	country: string;
	// Three-section display ("Name, Local, Province") and the full
	// hierarchy ("Name, Kelurahan, Kecamatan, Kabupaten, Provinsi,
	// Country") for the title attribute.
	label: string;
	fullName: string;
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

// The stored cache entry for a surface and location, whatever its
// age: the live refresh schedules its next tick from the entry's
// fetchedAt, so it needs the entry even after the lifetime has passed.
function readCacheEntry(cacheKey: string, location: Location): CachedWeather | null {
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
		// Same place (within ~1 km); the age check lives in the caller.
		if (
			Math.abs((cached.latitude ?? Number.NaN) - location.latitude) > 0.01 ||
			Math.abs((cached.longitude ?? Number.NaN) - location.longitude) > 0.01
		) {
			return null;
		}
		return cached as CachedWeather;
	} catch {
		return null;
	}
}

function getCachedWeather(cacheKey: string, location: Location): WeatherData | null {
	const entry = readCacheEntry(cacheKey, location);
	if (entry === null || Date.now() - entry.fetchedAt > getCacheLifetimeMs()) {
		return null;
	}
	return entry.weather;
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

/* Localised picker string: the English source text is the key into
 * the server-localized table; the fallback is the English text
 * itself, so a missing key still reads correctly. */
function t(key: string): string {
	const strings = getKkData().weatherStrings;
	return strings !== undefined && typeof strings[key] === 'string' && strings[key] !== '' ? strings[key] : key;
}

function tf(key: string, value: string): string {
	return t(key).replace('%s', value);
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
		// The stored full hierarchy survives the round-trip: the
		// title attribute ("Kelurahan, Kecamatan, Kota/Kab") reads
		// it from the stored location on every paint, cached ones
		// included. Dropping it here left every title at the
		// short display name.
		return {
			name: candidate.name,
			latitude: candidate.latitude,
			longitude: candidate.longitude,
			...(typeof candidate.fullName === 'string' && candidate.fullName.trim() !== ''
				? { fullName: candidate.fullName.trim() }
				: {}),
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
	// The server echoes the requested name; the full hierarchy comes
	// from the picked location itself, so it survives the cache.
	if (location.fullName !== undefined && location.fullName !== result.weather.name) {
		return { ...result.weather, fullName: location.fullName };
	}
	return result.weather;
}

/* "Cari kota" searches the geocoders directly from the browser,
 * never through admin-ajax: Open-Meteo first, Nominatim only when
 * it finds nothing. The browser's Referer identifies the request
 * to Nominatim; the 300 ms debounce in initSearch keeps the call
 * rate down. A failed lookup answers with an empty list, never an
 * error, so the search just shows no places. */
const GEOCODING_URL = 'https://geocoding-api.open-meteo.com/v1/search';
const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';

async function searchPlaces(query: string, signal: AbortSignal): Promise<SearchResult[]> {
	const trimmed = query.trim();
	if (trimmed.length < 2) {
		return [];
	}
	const openMeteo = await searchOpenMeteo(trimmed, signal);
	if (openMeteo.length > 0) {
		return openMeteo;
	}
	return searchNominatim(trimmed, signal);
}

async function searchOpenMeteo(query: string, signal: AbortSignal): Promise<SearchResult[]> {
	try {
		const url = `${GEOCODING_URL}?name=${encodeURIComponent(query)}&count=5&language=en&format=json`;
		const response = await fetch(url, { signal });
		if (!response.ok) {
			return [];
		}
		const body: unknown = await response.json();
		if (typeof body !== 'object' || body === null || !Array.isArray((body as { results?: unknown }).results)) {
			return [];
		}
		const results: SearchResult[] = [];
		for (const place of (body as { results: unknown[] }).results) {
			if (typeof place !== 'object' || place === null) {
				continue;
			}
			const p = place as Record<string, unknown>;
			if (typeof p.name !== 'string' || typeof p.latitude !== 'number' || typeof p.longitude !== 'number') {
				continue;
			}
			const admin4 = typeof p.admin4 === 'string' ? p.admin4.trim() : '';
			const admin3 = typeof p.admin3 === 'string' ? p.admin3.trim() : '';
			const admin2 = typeof p.admin2 === 'string' ? p.admin2.trim() : '';
			const admin1 = typeof p.admin1 === 'string' ? p.admin1.trim() : '';
			const countryName = typeof p.country === 'string' ? p.country.trim() : '';
			const local = admin4 !== '' ? admin4 : admin3 !== '' ? admin3 : admin2 !== '' ? admin2 : null;
			results.push({
				name: p.name,
				latitude: p.latitude,
				longitude: p.longitude,
				country: countryName,
				label: searchLabel(p.name, local, admin1 !== '' ? admin1 : null, countryName !== '' ? countryName : null),
				fullName: joinHierarchy([p.name, admin4, admin3, admin2, admin1, countryName]),
			});
		}
		return results;
	} catch (error: unknown) {
		if (error instanceof DOMException && error.name === 'AbortError') {
			throw error;
		}
		return [];
	}
}

/* Join hierarchy parts into one comma-separated form, dropping
 * empties. Levels are kept even when two share a spelling (Adana
 * the city in Adana the province): they are different levels, and
 * dropping one would lose a section the display must show. */
function joinHierarchy(parts: (string | null | undefined)[]): string {
	const out: string[] = [];
	for (const part of parts) {
		if (typeof part !== 'string') {
			continue;
		}
		const trimmed = part.trim();
		if (trimmed === '') {
			continue;
		}
		out.push(trimmed);
	}
	return out.join(', ');
}

/* The three-section display: the name, the most specific local
 * level (kelurahan / kecamatan / kabupaten), and the province (or
 * the country when there is no province). Every level the provider
 * returns rides the full form instead, on the title attribute. */
function searchLabel(name: string, local: string | null, province: string | null, country: string | null): string {
	const middle = local ?? province ?? country;
	const broad = local !== null ? (province ?? country) : country;
	return joinHierarchy([name, middle, broad]);
}

async function searchNominatim(query: string, signal: AbortSignal): Promise<SearchResult[]> {
	try {
		const url = `${NOMINATIM_SEARCH_URL}?format=json&limit=5&accept-language=id&addressdetails=1&q=${encodeURIComponent(query)}`;
		const response = await fetch(url, { signal });
		if (!response.ok) {
			return [];
		}
		const body: unknown = await response.json();
		if (!Array.isArray(body)) {
			return [];
		}
		const results: SearchResult[] = [];
		for (const row of body) {
			if (typeof row !== 'object' || row === null) {
				continue;
			}
			const r = row as Record<string, unknown>;
			if (typeof r.display_name !== 'string') {
				continue;
			}
			const latitude = Number(r.lat);
			const longitude = Number(r.lon);
			if (!Number.isFinite(latitude) || !Number.isFinite(longitude) || latitude < -90 || latitude > 90 || longitude < -180 || longitude > 180) {
				continue;
			}
			const parts = r.display_name.split(',').map((part) => part.trim()).filter((part) => part !== '');
			const name = parts[0] ?? '';
			if (name === '') {
				continue;
			}
			const address = typeof r.address === 'object' && r.address !== null ? (r.address as Record<string, unknown>) : null;
			const hamlet = addressPart(address, ['hamlet']);
			const kelurahan = addressPart(address, ['village', 'suburb']);
			const kecamatan = addressPart(address, ['town', 'city_district', 'district', 'municipality']);
			const kabupaten = addressPart(address, ['county', 'city']);
			const provinsi = addressPart(address, ['state']);
			const countryName = addressPart(address, ['country']) ?? (parts.length > 1 ? parts[parts.length - 1] : null);
			const local = kelurahan ?? kecamatan ?? kabupaten;
			results.push({
				name,
				latitude,
				longitude,
				country: countryName ?? '',
				label: address !== null ? searchLabel(name, local, provinsi, countryName) : parts.slice(0, 3).join(', '),
				fullName: address !== null ? joinHierarchy([name, hamlet, kelurahan, kecamatan, kabupaten, provinsi, countryName]) : parts.join(', '),
			});
		}
		return results;
	} catch (error: unknown) {
		if (error instanceof DOMException && error.name === 'AbortError') {
			throw error;
		}
		return [];
	}
}

/* Resolve coordinates to their area name ("Kabupaten Malang"), so a
 * GPS fix or a typed coordinate pair shows the place instead of the
 * anonymous "My location" / "Custom location". Null when the place
 * cannot be resolved - the caller keeps the generic label then. */
async function reverseGeocode(latitude: number, longitude: number): Promise<string | null> {
	const details = await reverseGeocodeDetails(latitude, longitude);
	return details === null ? null : details.name;
}

interface ReverseGeocodeDetails {
	name: string;
	fullName: string;
	kelurahan: string | null;
	kecamatan: string | null;
	kota: string | null;
}

/* Full area hierarchy for coordinates: the short display name plus
 * the full "Kelurahan, Kecamatan, Kota/Kab" form and the levels it
 * came from. The name is what the top bar and widget show; the full
 * form rides the title attribute. */
/* Resolve coordinates to the area hierarchy, directly from the
 * browser via Nominatim reverse - never through admin-ajax. Two
 * lookups, mirroring the server: zoom 16 for the fine level
 * (suburb/village - the kelurahan) and zoom 12 for the district
 * level (town - the kecamatan) plus the city/regency. One zoom
 * cannot give all three. Results are cached in memory by rounded
 * coordinates so a repeated lookup for the same area never hits
 * Nominatim twice. The browser's Referer identifies the request. */
const NOMINATIM_REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';
const reverseCache = new Map<string, ReverseGeocodeDetails | null>();

async function nominatimReverse(latitude: number, longitude: number, zoom: number): Promise<Record<string, unknown> | null> {
	try {
		const url = `${NOMINATIM_REVERSE_URL}?format=jsonv2&lat=${latitude}&lon=${longitude}&zoom=${zoom}&addressdetails=1&accept-language=en`;
		const response = await fetch(url);
		if (!response.ok) {
			return null;
		}
		const body: unknown = await response.json();
		if (typeof body !== 'object' || body === null) {
			return null;
		}
		const address = (body as { address?: unknown }).address;
		if (typeof address !== 'object' || address === null) {
			return null;
		}
		return address as Record<string, unknown>;
	} catch {
		return null;
	}
}

/* First non-empty string among the address keys, or null. */
function addressPart(address: Record<string, unknown> | null, keys: string[]): string | null {
	if (address === null) {
		return null;
	}
	for (const key of keys) {
		const value = address[key];
		if (typeof value === 'string' && value.trim() !== '') {
			return value.trim();
		}
	}
	return null;
}

async function reverseGeocodeDetails(latitude: number, longitude: number): Promise<ReverseGeocodeDetails | null> {
	const cacheKey = `${latitude.toFixed(2)},${longitude.toFixed(2)}`;
	const cached = reverseCache.get(cacheKey);
	if (cached !== undefined) {
		return cached;
	}
	const fine = await nominatimReverse(latitude, longitude, 16);
	let district: Record<string, unknown> | null = null;
	if (fine === null || addressPart(fine, ['town', 'city_district']) === null) {
		district = await nominatimReverse(latitude, longitude, 12);
	}
	const details = buildReverseDetails(fine, district);
	reverseCache.set(cacheKey, details);
	return details;
}

function buildReverseDetails(fine: Record<string, unknown> | null, district: Record<string, unknown> | null): ReverseGeocodeDetails | null {
	if (fine === null && district === null) {
		return null;
	}
	let kelurahan = addressPart(fine, ['suburb', 'village']);
	let kecamatan = addressPart(district, ['town', 'city_district']) ?? addressPart(fine, ['town', 'city_district']);
	let kota = addressPart(district, ['city', 'county']) ?? addressPart(fine, ['city', 'county']);
	if (kelurahan !== null && (kelurahan === kecamatan || kelurahan === kota)) {
		kelurahan = null;
	}
	if (kecamatan !== null && kecamatan === kota) {
		kecamatan = null;
	}
	if (kota === null) {
		kota = addressPart(district, ['state']) ?? addressPart(fine, ['state']);
	}
	if (kota === null) {
		return null;
	}
	const specific = kelurahan ?? kecamatan;
	const name = specific !== null ? `${specific}, ${kota}` : kota;
	const parts = [kelurahan, kecamatan, kota].filter((part): part is string => part !== null && part !== '');
	return {
		name,
		fullName: parts.join(', '),
		kelurahan,
		kecamatan,
		kota,
	};
}

/* Attach the full hierarchy to a location, unless it adds nothing
 * over the display name. */
function locationWithDetails(base: Location, details: ReverseGeocodeDetails | null): Location {
	if (details === null || details.fullName === base.name) {
		return base;
	}
	return { ...base, fullName: details.fullName };
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
	// The tooltip carries the full hierarchy ("Kelurahan, Kecamatan,
	// Kota/Kab"); the slot itself shows only the short display
	// ("Kelurahan, Kota/Kab"), truncated with an ellipsis when long.
	slot.setAttribute('title', weather.fullName ?? topbarLocation.fullName ?? weather.name);
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
	slot.setAttribute('title', location.fullName ?? location.name);
}

// Paint one widget from one weather object.
function renderWidget(widget: HTMLElement, weather: WeatherData): void {
	const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
	const place = widget.querySelector<HTMLElement>('.kk-weather-place');
	if (place) {
		place.textContent = weather.name;
		// The tooltip carries the full hierarchy; the widget shows
		// only the short display, truncated with an ellipsis when long.
		const stored = getTargetLocation({ kind: 'widget', element: widget, key: widgetKey(widget) });
		place.setAttribute('title', weather.fullName ?? stored.fullName ?? weather.name);
	}
	if (body) {
		renderWidgetBody(body, weather, widget.dataset.showDetails === '1', widget.dataset.showIcon !== '0', widgetTempUnit(widget));
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

function renderWidgetBody(body: HTMLElement, weather: WeatherData, showDetails: boolean, showIcon: boolean, unit: TempUnit): void {
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
	temp.textContent = formatTemp(weather.temperature, unit);
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
		rows.push(['Feels like', formatTemp(weather.feels_like, unit)]);
	}
	if (weather.high !== null && weather.low !== null) {
		rows.push(['High / Low', `${formatTemp(weather.high, unit)} / ${formatTemp(weather.low, unit)}`]);
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
		labelEl.textContent = t(label);
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
	empty.textContent = t('Weather is unavailable right now.');
	body.appendChild(empty);
}

// The fresh weather on success, null when the fetch failed - the
// live update broadcasts the returned weather, so it needs it back.
async function refreshTopbar(location: Location): Promise<WeatherData | null> {
	try {
		const weather = await fetchWeather(TOPBAR_DATA_KEY, location);
		renderTopbar(weather);
		return weather;
	} catch {
		// A failed revalidation keeps a cached display (it is still
		// fresh); only a page with no data at all - still showing the
		// loading state - hides the slot.
		const slot = $('#kk-topbar-weather');
		if (slot && slot.querySelector('.kk-topbar-weather-loading')) {
			hideTopbar();
		}
		return null;
	}
}

async function refreshWidget(widget: HTMLElement, location: Location): Promise<WeatherData | null> {
	try {
		const weather = await fetchWeather(widgetDataKey(widgetKey(widget)), location);
		renderWidget(widget, weather);
		return weather;
	} catch {
		// Keep the server-side first paint when it already shows the
		// widget's location; only a stale paint gets the honest empty.
		const def = widgetDefaultLocation(widget);
		const samePlace =
			Math.abs(def.latitude - location.latitude) < 0.01 &&
			Math.abs(def.longitude - location.longitude) < 0.01;
		if (samePlace) {
			return null;
		}
		const body = widget.querySelector<HTMLElement>('[data-kk-weather-body]');
		if (body) {
			renderWidgetEmpty(body);
		}
		return null;
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
/* Full hierarchy of the search result currently staged on the map.
 * The name field only holds the short display, so the complete
 * form ("Kelurahan, Kecamatan, Kabupaten, Provinsi, Country") is
 * kept here and attached to the location when Update applies it,
 * so the title attribute reads the full form. Cleared whenever the
 * staged place changes (pin move, name edit, coordinates, quick
 * pick, geolocation, reset) and when the picker opens or closes. */
let stagedFullName: string | null = null;

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
		line.textContent = tf('Current location: %s', `${location.name} (${formatCoords(location)})`);
	}
}

function applyLocation(location: Location, target: PickerTarget, announce = true): void {
	setTargetLocation(target, location);
	updateCurrentLine();
	syncCoordsInputs();
	if (announce) {
		setStatus(tf('Location set to %s.', location.name));
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
const MAP_ZOOM = 15;

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
}

function mapEls(): MapElements | null {
	const map = $('[data-kk-weather-map]');
	const name = $('[data-kk-weather-map-name]') as HTMLInputElement | null;
	const coords = $('[data-kk-weather-map-coords]');
	if (!map || !name || !coords) {
		return null;
	}
	return { map, name, coords };
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
	const lat = $('[data-kk-weather-lat]') as HTMLInputElement | null;
	const lon = $('[data-kk-weather-lon]') as HTMLInputElement | null;
	if (lat) {
		lat.value = String(latitude);
	}
	if (lon) {
		lon.value = String(longitude);
	}
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
	stagedFullName = null;
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

/* The location the modal is currently staging, read from the
 * coordinate and name fields the staging paths keep in sync. The
 * map opens on this place, not on the applied location, so a search
 * or a pin move made while the map was hidden is not lost. */
function stagedLocationFromFields(): Location {
	const applied = getTargetLocation(pickerTarget);
	const latInput = $('[data-kk-weather-lat]') as HTMLInputElement | null;
	const lonInput = $('[data-kk-weather-lon]') as HTMLInputElement | null;
	const nameInput = $('[data-kk-weather-map-name]') as HTMLInputElement | null;
	const latitude = latInput ? Number(latInput.value) : NaN;
	const longitude = lonInput ? Number(lonInput.value) : NaN;
	const name = nameInput ? nameInput.value.trim() : '';
	return {
		name: name !== '' ? name : applied.name,
		latitude: Number.isFinite(latitude) ? latitude : applied.latitude,
		longitude: Number.isFinite(longitude) ? longitude : applied.longitude,
		source: applied.source,
	};
}

/* Stage the map for the surface being edited: the marker starts on
 * the staged place, with its name in the field. The map is built
 * lazily on the first show - Leaflet needs a laid-out box, and the
 * map section is hidden until the visitor asks for it. */
function stageMapForTarget(): void {
	const els = mapEls();
	if (!els) {
		return;
	}
	const location = stagedLocationFromFields();
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

/* Show or hide the map section. Showing builds the Leaflet map on
 * the first show and re-measures it on later shows, so the tiles
 * always fill the box. */
function setMapVisible(visible: boolean): void {
	const wrap = $('.kk-weather-map-wrap');
	const toggle = $('[data-kk-weather-map-toggle]') as HTMLButtonElement | null;
	if (!wrap || !toggle) {
		return;
	}
	wrap.hidden = !visible;
	toggle.textContent = visible ? `- ${t('Hide map')}` : `+ ${t('Show map')}`;
	toggle.setAttribute('aria-expanded', visible ? 'true' : 'false');
	if (visible) {
		stageMapForTarget();
	}
}

function initMapToggle(): void {
	const toggle = $('[data-kk-weather-map-toggle]') as HTMLButtonElement | null;
	const wrap = $('.kk-weather-map-wrap');
	if (!toggle || !wrap) {
		return;
	}
	toggle.addEventListener('click', () => {
		setMapVisible(wrap.hidden);
	});
}

/* Stage a location on the map without applying it: the marker, the
 * name field and the coordinate inputs follow, the weather on the
 * page only changes when the visitor confirms with Update. Without
 * the map section (stale cached markup) the location applies
 * directly, as it used to. */
function stageLocation(location: Location): void {
	stagedFullName = null;
	if (mapEls() === null) {
		applyLocation(location, pickerTarget);
		return;
	}
	setMapPin(location.latitude, location.longitude, location.name);
	setStatus(tf('Moved the map to %s. Press "Update" to apply it.', location.name));
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
		stagedFullName = null;
	});
	// Update is the only path that applies the staged location.
	// Order: apply the staged lat/lon first, then close
	// immediately. The weather is fetched after the close, as a
	// Promise the close never awaits: applyLocation already kicks
	// the fetch off fire-and-forget, and awaiting a lookup before
	// closing left the picker open while the call ran and read as
	// a dead button. The position comes from the fields, not the
	// marker: the marker only exists once the map has been shown
	// (lazy init), but a search, coordinates or geolocation can
	// stage a place without ever opening the map.
	const update = $('[data-kk-weather-update]') as HTMLButtonElement | null;
	update?.addEventListener('click', () => {
		const staged = stagedLocationFromFields();
		const typed = els.name.value.trim();
		const base: Location =
			typed !== ''
				? { name: typed, latitude: staged.latitude, longitude: staged.longitude, source: 'map' }
				: { name: t('Custom location'), latitude: staged.latitude, longitude: staged.longitude, source: 'map' };
		// A search result carries its full hierarchy from the
		// provider (province + country included); prefer it over
		// the reverse lookup, which only knows the local levels.
		const location =
			stagedFullName !== null && stagedFullName !== base.name
				? { ...base, fullName: stagedFullName }
				: base;
		applyLocation(location, pickerTarget);
		// Whether a search result staged a full hierarchy: read
		// before closePicker, which resets stagedFullName. The
		// background lookup only runs when the staged place had
		// none (a map click); a search fullName must never be
		// overwritten by the reverse lookup.
		const hadSearchFullName = stagedFullName !== null;
		closePicker();
		// After the close, resolve the area hierarchy in the
		// background (a Promise the close never awaited). It only
		// adds the full form for the title when the staged place
		// had none (a map click): the name itself is final at
		// apply time, so the background lookup never rewrites it
		// and never races the weather fetch already in flight.
		if (!hadSearchFullName) {
			void (async () => {
				const details = await reverseGeocodeDetails(staged.latitude, staged.longitude);
				if (details === null) {
					return;
				}
				const current = getTargetLocation(pickerTarget);
				if (current.latitude !== staged.latitude || current.longitude !== staged.longitude) {
					return;
				}
				const refined = locationWithDetails(current, details);
				if (refined.fullName === current.fullName) {
					return;
				}
				setTargetLocation(pickerTarget, refined);
				if (pickerTarget.kind === 'topbar') {
					const slot = $('#kk-topbar-weather');
					slot?.setAttribute('title', refined.fullName ?? refined.name);
				} else {
					const place = pickerTarget.element.querySelector<HTMLElement>('.kk-weather-place');
					place?.setAttribute('title', refined.fullName ?? refined.name);
				}
			})();
		}
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
	const staged = getTargetLocation(pickerTarget);
	stagedFullName = null;
	setMapPin(staged.latitude, staged.longitude, staged.name);
	setMapVisible(false);
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
	stagedFullName = null;
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
			stageLocation({ ...city, source: isCustom ? 'search' : 'quick' });
			renderStrip();
		});
		item.appendChild(chip);
		if (isCustom) {
			const remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'kk-weather-chip-remove';
			remove.setAttribute('aria-label', tf('Remove %s from quick picks', city.name));
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
		setStatus(t('Searching...'));
		timer = window.setTimeout(() => {
			controller?.abort();
			controller = new AbortController();
			searchPlaces(query, controller.signal)
				.then((places) => {
					results.textContent = '';
					if (places.length === 0) {
						setStatus(t('No places found. Try another name.'));
						return;
					}
					setStatus('');
					for (const place of places) {
						const item = document.createElement('li');
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'kk-weather-result';
						button.textContent = place.label;
						button.title = place.fullName;
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
										...(place.fullName !== '' && place.fullName !== place.name ? { fullName: place.fullName } : {}),
									},
									pickerTarget,
								);
								results.textContent = '';
								input.value = place.name;
								renderStrip();
								return;
							}
							setMapPin(place.latitude, place.longitude, place.name);
							stagedFullName = place.fullName !== '' && place.fullName !== place.name ? place.fullName : null;
							results.textContent = '';
							input.value = place.name;
							setStatus(tf('Moved the map to %s. Press "Update" to apply it.', place.name));
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
					setStatus(t('Search is unavailable right now. Try coordinates instead.'));
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
				errorEl.textContent = t('Enter a latitude between -90 and 90 and a longitude between -180 and 180.');
				errorEl.hidden = false;
			}
			return;
		}
		if (errorEl) {
			errorEl.textContent = '';
			errorEl.hidden = true;
		}
		// Typed coordinates have no name of their own: resolve the area
		// first so the top bar reads e.g. "Pakis, Kabupaten Malang"
		// instead of the anonymous "Custom location".
		void (async () => {
			const details = await reverseGeocodeDetails(latitude, longitude);
			const base: Location = { name: details?.name ?? t('Custom location'), latitude, longitude, source: 'coords' };
			stageLocation(locationWithDetails(base, details));
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
			setStatus(t('Geolocation is not supported by this browser. Search for a city or enter coordinates.'));
			return;
		}
		setStatus(t('Asking for your location...'));
		navigator.geolocation.getCurrentPosition(
			(position) => {
				void (async () => {
					const resolved: Location = {
						name: t('My location'),
						latitude: position.coords.latitude,
						longitude: position.coords.longitude,
						source: 'geolocation',
					};
					// A GPS fix at a city that is already a quick pick is
					// that city: select its chip instead of leaving an
					// anonymous "My location" that duplicates it on Add.
					const existing = findExistingPick(resolved);
					if (existing) {
						stageLocation({ ...existing, source: existing.source });
					} else {
						// Otherwise resolve the fix to its area name so
						// the top bar reads "Pakis, Kabupaten Malang", not
						// "My location".
						const details = await reverseGeocodeDetails(resolved.latitude, resolved.longitude);
						const base: Location = { ...resolved, name: details?.name ?? t('My location') };
						stageLocation(locationWithDetails(base, details));
					}
					renderStrip();
				})();
			},
			(error) => {
				if (error.code === error.PERMISSION_DENIED) {
					setStatus(t('Location permission was denied. You can still search for a city or enter coordinates.'));
				} else if (error.code === error.TIMEOUT) {
					setStatus(t('Finding your location timed out. Try again, or search for a city.'));
				} else {
					setStatus(t('Your location could not be determined. Search for a city or enter coordinates.'));
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
		stageLocation(def);
		renderStrip();
		setStatus(tf('Location reset to %s.', def.name));
	});
	initMap();
	initMapToggle();
	initStrip();
	initSearch();
	initCoords();
	initGeolocate();
}

/* Live update                                                        */
/*                                                                    */
/* A tab left open re-checks its surfaces on the cache lifetime, so   */
/* the numbers stay current without a reload. The check is            */
/* cache-first; when the cache is stale, one tab fetches and the      */
/* rest repaint from its broadcast. The fetch is claimed in           */
/* localStorage - a broadcast alone cannot stop two tabs whose       */
/* timers fire in the same beat from fetching twice. A hidden tab     */
/* fetches nothing: the timer stops on hide and the surfaces          */
/* re-check when the tab comes back.                                  */

const WEATHER_CHANNEL_NAME = 'kk-weather';
// A claim older than this belongs to a tab that died mid-fetch.
const CLAIM_TTL_MS = 30 * 1000;
// The beat between claiming a fetch and re-reading the claim: the
// last writer wins, so a tab that lost the race sees the winner.
const CLAIM_VERIFY_MS = 100;
// How long a tab that stood down waits for the winner's broadcast
// before retrying on its own.
const BROADCAST_WAIT_MS = 30 * 1000;
// Minimum gap between live-refresh ticks; keeps the timer chain from
// spinning while a fetch is still in flight.
const LIVE_TICK_FLOOR_MS = 1000;

const LIVE_TAB_ID = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;

type WeatherBroadcast =
	| { type: 'kk-weather-fetching'; key: string; tab: string }
	| { type: 'kk-weather-updated'; key: string; weather: WeatherData; latitude: number; longitude: number; fetchedAt: number };

let weatherChannel: BroadcastChannel | null = null;
const lastBroadcastAt = new Map<string, number>();
const fetchingElsewhereAt = new Map<string, number>();
const lastFetchFailedAt = new Map<string, number>();
const pendingRecheck = new Map<string, number>();
let liveRefreshTimer: number | null = null;

function claimKey(dataKey: string): string {
	return `kk-weather-claim-${dataKey}`;
}

function readClaim(dataKey: string): { tab: string; at: number } | null {
	try {
		const raw = window.localStorage.getItem(claimKey(dataKey));
		if (!raw) {
			return null;
		}
		const parsed = JSON.parse(raw) as { tab?: unknown; at?: unknown };
		if (typeof parsed.tab !== 'string' || typeof parsed.at !== 'number') {
			return null;
		}
		return { tab: parsed.tab, at: parsed.at };
	} catch {
		return null;
	}
}

// One tab fetches a stale surface: it claims the key in localStorage
// and re-reads it after a beat, so a tab that lost the race sees the
// winner's claim and stands down instead of fetching too. A claim
// older than CLAIM_TTL_MS belongs to a tab that died mid-fetch and
// may be taken over.
async function tryClaim(dataKey: string): Promise<boolean> {
	const existing = readClaim(dataKey);
	if (existing !== null && Date.now() - existing.at < CLAIM_TTL_MS) {
		return false;
	}
	try {
		window.localStorage.setItem(claimKey(dataKey), JSON.stringify({ tab: LIVE_TAB_ID, at: Date.now() }));
	} catch {
		return false;
	}
	await new Promise<void>((resolve) => {
		window.setTimeout(resolve, CLAIM_VERIFY_MS);
	});
	const after = readClaim(dataKey);
	return after !== null && after.tab === LIVE_TAB_ID;
}

function releaseClaim(dataKey: string): void {
	const claim = readClaim(dataKey);
	if (claim !== null && claim.tab === LIVE_TAB_ID) {
		try {
			window.localStorage.removeItem(claimKey(dataKey));
		} catch {
			// A stale claim expires on its own.
		}
	}
}

function openWeatherChannel(): BroadcastChannel | null {
	if (typeof window.BroadcastChannel !== 'function') {
		return null;
	}
	try {
		const channel = new BroadcastChannel(WEATHER_CHANNEL_NAME);
		channel.addEventListener('message', onWeatherBroadcast);
		return channel;
	} catch {
		return null;
	}
}

function broadcastWeather(message: WeatherBroadcast): void {
	if (weatherChannel === null) {
		return;
	}
	try {
		weatherChannel.postMessage(message);
	} catch {
		// A closed channel just means no other tab hears it.
	}
}

function isWeatherBroadcast(value: unknown): value is WeatherBroadcast {
	if (!value || typeof value !== 'object') {
		return false;
	}
	const message = value as {
		type?: unknown;
		key?: unknown;
		tab?: unknown;
		weather?: unknown;
		latitude?: unknown;
		longitude?: unknown;
		fetchedAt?: unknown;
	};
	if (message.type !== 'kk-weather-fetching' && message.type !== 'kk-weather-updated') {
		return false;
	}
	if (typeof message.key !== 'string' || message.key === '') {
		return false;
	}
	if (message.type === 'kk-weather-fetching') {
		return typeof message.tab === 'string';
	}
	return (
		typeof message.weather === 'object' &&
		message.weather !== null &&
		typeof message.latitude === 'number' &&
		typeof message.longitude === 'number' &&
		typeof message.fetchedAt === 'number'
	);
}

function onWeatherBroadcast(event: MessageEvent): void {
	if (!isWeatherBroadcast(event.data)) {
		return;
	}
	const message = event.data;
	if (message.type === 'kk-weather-fetching') {
		fetchingElsewhereAt.set(message.key, Date.now());
		return;
	}
	// The fetch is done: the sibling is no longer in flight, so a
	// later tick must not stand down on its stale "fetching" note.
	// Without this the loser of a boot race stays blocked for the
	// whole BROADCAST_WAIT_MS and its interval never fetches.
	fetchingElsewhereAt.delete(message.key);
	lastBroadcastAt.set(message.key, message.fetchedAt);
	repaintSurfaceFromBroadcast(message.key, message.weather, message.latitude, message.longitude);
}

// A sibling tab fetched fresh weather: repaint the surface that owns
// this data key, but only when its location still matches the fetch -
// a tab that moved its top bar to another city must not paint the
// other tab's city over its own.
function repaintSurfaceFromBroadcast(key: string, weather: WeatherData, latitude: number, longitude: number): void {
	const matches = (location: Location): boolean =>
		Math.abs(location.latitude - latitude) < 0.01 && Math.abs(location.longitude - longitude) < 0.01;
	if (key === TOPBAR_DATA_KEY) {
		if (matches(topbarLocation)) {
			renderTopbar(weather);
		}
		return;
	}
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		if (widgetDataKey(widgetKey(widget)) !== key) {
			return;
		}
		const location = getTargetLocation({ kind: 'widget', element: widget, key: widgetKey(widget) });
		if (matches(location)) {
			renderWidget(widget, weather);
		}
	});
}

interface SurfaceRefresh {
	key: string;
	location: () => Location;
	refresh: () => Promise<WeatherData | null>;
	repaint: (weather: WeatherData) => void;
}

function topbarSurface(): SurfaceRefresh {
	return {
		key: TOPBAR_DATA_KEY,
		location: () => topbarLocation,
		refresh: () => refreshTopbar(topbarLocation),
		repaint: (weather) => renderTopbar(weather),
	};
}

function widgetSurface(widget: HTMLElement): SurfaceRefresh {
	const key = widgetKey(widget);
	return {
		key: widgetDataKey(key),
		location: () => getTargetLocation({ kind: 'widget', element: widget, key }),
		refresh: () => refreshWidget(widget, getTargetLocation({ kind: 'widget', element: widget, key })),
		repaint: (weather) => renderWidget(widget, weather),
	};
}

function weatherSurfaces(): SurfaceRefresh[] {
	const surfaces: SurfaceRefresh[] = [];
	if ($('#kk-topbar-weather')) {
		surfaces.push(topbarSurface());
	}
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		surfaces.push(widgetSurface(widget));
	});
	return surfaces;
}

// Fetch one surface with multi-tab dedup: claim the key, fetch, and
// broadcast the result so sibling tabs repaint without fetching.
// Null when another tab owns the fetch (its broadcast repaints this
// tab) or the fetch failed.
async function fetchSurfaceShared(surface: SurfaceRefresh): Promise<WeatherData | null> {
	if (!(await tryClaim(surface.key))) {
		scheduleRecheck(surface, true);
		return null;
	}
	try {
		broadcastWeather({ type: 'kk-weather-fetching', key: surface.key, tab: LIVE_TAB_ID });
		const weather = await surface.refresh();
		if (weather === null) {
			lastFetchFailedAt.set(surface.key, Date.now());
			return null;
		}
		const fetchedAt = Date.now();
		lastBroadcastAt.set(surface.key, fetchedAt);
		const location = surface.location();
		broadcastWeather({
			type: 'kk-weather-updated',
			key: surface.key,
			weather,
			latitude: location.latitude,
			longitude: location.longitude,
			fetchedAt,
		});
		return weather;
	} finally {
		// This tab's fetch is over (success or failure): the key is
		// no longer in flight, so a stale "fetching" note from an
		// earlier sibling fetch must not block the next tick.
		fetchingElsewhereAt.delete(surface.key);
		releaseClaim(surface.key);
	}
}

// One interval tick per surface: a fresh cache means paint it and
// move on - another tab may have fetched it while this one stood
// down. A stale cache means claim the fetch; the loser waits for the
// winner's broadcast instead of fetching too.
async function refreshSurfaceIfStale(surface: SurfaceRefresh, allowRetry = true): Promise<void> {
	const location = surface.location();
	const cached = getCachedWeather(surface.key, location);
	if (cached !== null) {
		surface.repaint(cached);
		return;
	}
	const broadcastAt = lastBroadcastAt.get(surface.key);
	if (broadcastAt !== undefined && Date.now() - broadcastAt < getRefreshIntervalMs()) {
		return;
	}
	const fetchingAt = fetchingElsewhereAt.get(surface.key);
	if (fetchingAt !== undefined && Date.now() - fetchingAt < BROADCAST_WAIT_MS) {
		scheduleRecheck(surface, allowRetry);
		return;
	}
	await fetchSurfaceShared(surface);
}

// A tab that stood down retries once after the winner should have
// answered - the winner may have failed its fetch, in which case no
// broadcast ever arrives and the next interval tick is too far away.
function scheduleRecheck(surface: SurfaceRefresh, allowRetry: boolean): void {
	if (!allowRetry || pendingRecheck.has(surface.key)) {
		return;
	}
	const timer = window.setTimeout(() => {
		pendingRecheck.delete(surface.key);
		void refreshSurfaceIfStale(surface, false);
	}, BROADCAST_WAIT_MS);
	pendingRecheck.set(surface.key, timer);
}

function clearPendingRechecks(): void {
	for (const timer of pendingRecheck.values()) {
		window.clearTimeout(timer);
	}
	pendingRecheck.clear();
}

// Refresh cadence: the cache lifetime, so a tick lands just as the
// cache goes stale. A 0 lifetime (cache disabled) still refreshes on
// the default 15-minute cadence - the setting only disables the
// browser cache, not the live update.
function getRefreshIntervalMs(): number {
	const lifetime = getCacheLifetimeMs();
	return lifetime > 0 ? lifetime : 15 * 60 * 1000;
}

// Delay until the next tick: the moment the oldest surface cache goes
// stale, so the tick lands on the lifetime boundary instead of a
// fixed grid that drifts a full interval late. No cache entry means
// the cadence; a recent failure means one interval after the failure,
// not an immediate retry storm.
function nextRefreshDelayMs(): number {
	const interval = getRefreshIntervalMs();
	let delay = interval;
	for (const surface of weatherSurfaces()) {
		const entry = readCacheEntry(surface.key, surface.location());
		if (entry === null) {
			continue;
		}
		const failedAt = lastFetchFailedAt.get(surface.key);
		if (failedAt !== undefined && Date.now() - failedAt < interval) {
			const retryIn = interval - (Date.now() - failedAt);
			if (retryIn < delay) {
				delay = retryIn;
			}
			continue;
		}
		const remaining = interval - (Date.now() - entry.fetchedAt);
		if (remaining < delay) {
			delay = remaining;
		}
	}
	return Math.max(LIVE_TICK_FLOOR_MS, delay);
}

function refreshAllSurfaces(): void {
	for (const surface of weatherSurfaces()) {
		void refreshSurfaceIfStale(surface);
	}
}

function scheduleLiveRefresh(): void {
	stopLiveRefresh();
	liveRefreshTimer = window.setTimeout(() => {
		liveRefreshTimer = null;
		refreshAllSurfaces();
		scheduleLiveRefresh();
	}, nextRefreshDelayMs());
}

function stopLiveRefresh(): void {
	if (liveRefreshTimer !== null) {
		window.clearTimeout(liveRefreshTimer);
		liveRefreshTimer = null;
	}
}

// A hidden tab fetches nothing: the timer stops when the page hides
// and the surfaces re-check the moment it comes back, so idle tabs
// never join a fetch storm.
function initLiveRefresh(): void {
	if (weatherSurfaces().length === 0) {
		return;
	}
	weatherChannel = openWeatherChannel();
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'hidden') {
			stopLiveRefresh();
			clearPendingRechecks();
			return;
		}
		refreshAllSurfaces();
		scheduleLiveRefresh();
	});
	if (document.visibilityState !== 'hidden') {
		scheduleLiveRefresh();
	}
}

/* Boot                                                                */

// A stored "My location" / "Custom location" predates area names:
// resolve it once in the background so the surface shows the area
// instead of the generic label. The first paint does not wait for
// it - the name updates in place when the lookup returns.
function upgradeGenericName(target: PickerTarget): void {
	const stored = getTargetLocation(target);
	// Both the English and the localized generic labels: older stored
	// entries carry the English form, newer ones the site language.
	const genericNames = ['My location', t('My location'), 'Custom location', t('Custom location')];
	if (!genericNames.includes(stored.name)) {
		return;
	}
	void reverseGeocodeDetails(stored.latitude, stored.longitude).then((details) => {
		if (!details) {
			return;
		}
		// Skip when the visitor already picked another place meanwhile.
		if (getTargetLocation(target) !== stored) {
			return;
		}
		const next = locationWithDetails({ ...stored, name: details.name }, details);
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
		// bounce - then revalidate in the background so the numbers
		// stay current. The shared fetch dedupes across tabs: only
		// one tab fetches a stale surface, the rest repaint from its
		// broadcast.
		const cached = getCachedWeather(TOPBAR_DATA_KEY, topbarLocation);
		if (cached) {
			renderTopbar(cached);
		} else {
			renderTopbarLoading(topbarLocation);
		}
		void fetchSurfaceShared(topbarSurface());
	}
	// Every widget revalidates for its own location; a widget without
	// a stored location keeps its server-side first paint on failure.
	$all<HTMLElement>('[data-kk-weather-widget]').forEach((widget) => {
		void fetchSurfaceShared(widgetSurface(widget));
	});
	initPicker();
	initLiveRefresh();
});
