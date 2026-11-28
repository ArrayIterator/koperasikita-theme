/**
 * Koperasi Kita - client-side geocoding for the location pickers.
 *
 * Every picker searches the same way, straight from the browser:
 * Open-Meteo first, Nominatim (OpenStreetMap) only when Open-Meteo
 * finds nothing. No WordPress backend in the loop, so a lookup costs
 * no admin-ajax round trip. Reverse geocoding is Nominatim reverse,
 * with the same kelurahan/kecamatan/kota hierarchy the server used
 * to build, so a pin placed by hand resolves to the same name.
 */

export interface GeocodedPlace {
	name: string;
	latitude: number;
	longitude: number;
	country: string;
}

export interface ReverseGeocodeDetails {
	name: string;
	fullName: string;
	kelurahan: string | null;
	kecamatan: string | null;
	kota: string | null;
	latitude: number;
	longitude: number;
}

const OPEN_METEO_URL = 'https://geocoding-api.open-meteo.com/v1/search';
const NOMINATIM_SEARCH_URL = 'https://nominatim.openstreetmap.org/search';
const NOMINATIM_REVERSE_URL = 'https://nominatim.openstreetmap.org/reverse';
const SEARCH_LIMIT = 5;
const MIN_QUERY = 2;

/** A coordinate from a string or number, or null when out of range. */
function parseCoord(value: unknown, min: number, max: number): number | null {
	const n = typeof value === 'number' ? value : typeof value === 'string' ? Number(value) : NaN;
	if (!Number.isFinite(n) || n < min || n > max) {
		return null;
	}
	return n;
}

/** The label under a place name: finest admin area first, so
 * same-named places in different regencies read differently. */
function placeLabel(place: Record<string, unknown>): string {
	const parts: string[] = [];
	for (const key of ['admin2', 'admin1', 'country']) {
		const value = place[key];
		if (typeof value === 'string' && value.trim() !== '') {
			parts.push(value.trim());
		}
	}
	return parts.join(', ');
}

/** One provider search: the rows, or null when the provider itself
 * could not be reached (as opposed to answering with no results). */
async function searchOpenMeteo(query: string): Promise<GeocodedPlace[] | null> {
	let body: unknown;
	try {
		const response = await fetch(
			`${OPEN_METEO_URL}?${new URLSearchParams({ name: query, count: String(SEARCH_LIMIT), language: 'en', format: 'json' })}`,
			{ headers: { Accept: 'application/json' } },
		);
		if (!response.ok) {
			return null;
		}
		body = (await response.json()) as unknown;
	} catch {
		return null;
	}

	if (typeof body !== 'object' || body === null || !Array.isArray((body as { results?: unknown }).results)) {
		return [];
	}

	const found: GeocodedPlace[] = [];
	for (const row of (body as { results: unknown[] }).results) {
		if (typeof row !== 'object' || row === null) {
			continue;
		}
		const place = row as Record<string, unknown>;
		if (typeof place.name !== 'string' || place.name.trim() === '') {
			continue;
		}
		const latitude = parseCoord(place.latitude, -90, 90);
		const longitude = parseCoord(place.longitude, -180, 180);
		if (latitude === null || longitude === null) {
			continue;
		}
		found.push({ name: place.name.trim(), latitude, longitude, country: placeLabel(place) });
	}
	return found;
}

/** Nominatim search: the first comma segment is the place name, the
 * rest is the address - the same split the server used. */
async function searchNominatim(query: string): Promise<GeocodedPlace[] | null> {
	let body: unknown;
	try {
		const response = await fetch(
			`${NOMINATIM_SEARCH_URL}?${new URLSearchParams({ format: 'json', limit: String(SEARCH_LIMIT), 'accept-language': 'id', q: query })}`,
			{ headers: { Accept: 'application/json' } },
		);
		if (!response.ok) {
			return null;
		}
		body = (await response.json()) as unknown;
	} catch {
		return null;
	}

	if (!Array.isArray(body)) {
		return [];
	}

	const found: GeocodedPlace[] = [];
	for (const row of body as unknown[]) {
		if (typeof row !== 'object' || row === null) {
			continue;
		}
		const item = row as Record<string, unknown>;
		if (typeof item.lat !== 'string' || typeof item.lon !== 'string' || typeof item.display_name !== 'string') {
			continue;
		}
		const latitude = parseCoord(item.lat, -90, 90);
		const longitude = parseCoord(item.lon, -180, 180);
		if (latitude === null || longitude === null) {
			continue;
		}
		const parts = item.display_name
			.split(',')
			.map((part) => part.trim())
			.filter((part) => part !== '');
		const name = parts[0];
		if (name === undefined || name === '') {
			continue;
		}
		found.push({ name, latitude, longitude, country: parts.slice(1).join(', ') });
	}
	return found;
}

/**
 * Search places by name: Open-Meteo first, Nominatim only when
 * Open-Meteo finds nothing. Throws only when neither provider can
 * be reached at all, so callers can tell "unavailable" apart from
 * "no places found".
 */
export async function searchPlaces(query: string): Promise<GeocodedPlace[]> {
	const trimmed = query.trim();
	if (trimmed.length < MIN_QUERY) {
		return [];
	}

	const openMeteo = await searchOpenMeteo(trimmed);
	if (openMeteo !== null && openMeteo.length > 0) {
		return openMeteo;
	}

	const nominatim = await searchNominatim(trimmed);
	if (nominatim !== null) {
		return nominatim;
	}

	if (openMeteo === null) {
		throw new Error('Geocoding unavailable');
	}
	return [];
}

/** First non-empty string among the address keys, or null. */
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

/** One keyless Nominatim reverse lookup at the given zoom, returning
 * the address parts or null when the lookup fails. */
async function nominatimReverse(
	latitude: number,
	longitude: number,
	zoom: number,
): Promise<Record<string, unknown> | null> {
	let body: unknown;
	try {
		const response = await fetch(
			`${NOMINATIM_REVERSE_URL}?${new URLSearchParams({ format: 'jsonv2', lat: String(latitude), lon: String(longitude), zoom: String(zoom), addressdetails: '1', 'accept-language': 'en' })}`,
			{ headers: { Accept: 'application/json' } },
		);
		if (!response.ok) {
			return null;
		}
		body = (await response.json()) as unknown;
	} catch {
		return null;
	}
	if (typeof body !== 'object' || body === null) {
		return null;
	}
	const address = (body as { address?: unknown }).address;
	if (typeof address !== 'object' || address === null) {
		return null;
	}
	return address as Record<string, unknown>;
}

/** Successful reverse lookups, keyed by rounded coordinates: a
 * failure is retried on the next lookup instead of caching an
 * empty answer. */
const reverseCache = new Map<string, ReverseGeocodeDetails>();

/**
 * Resolve coordinates to the area hierarchy: kelurahan (suburb /
 * village), kecamatan (district) and kota/kabupaten (city/regency).
 * Two lookups: zoom 16 for the fine level and zoom 12 for the
 * district level plus the city - one zoom cannot give all three.
 * Null when the place cannot be resolved; the caller keeps whatever
 * label it already has then.
 */
export async function reverseGeocode(latitude: number, longitude: number): Promise<ReverseGeocodeDetails | null> {
	const key = `${latitude.toFixed(2)},${longitude.toFixed(2)}`;
	const cached = reverseCache.get(key);
	if (cached !== undefined) {
		return cached;
	}

	const fine = await nominatimReverse(latitude, longitude, 16);
	let district: Record<string, unknown> | null = null;
	if (fine === null || addressPart(fine, ['town', 'city_district']) === null) {
		district = await nominatimReverse(latitude, longitude, 12);
	}

	if (fine === null && district === null) {
		return null;
	}

	let kelurahan = addressPart(fine, ['suburb', 'village']);
	let kecamatan = addressPart(district, ['town', 'city_district']) ?? addressPart(fine, ['town', 'city_district']);
	let kota = addressPart(district, ['city', 'county']) ?? addressPart(fine, ['city', 'county']);

	// A fine level that duplicates the district or the city is not
	// a level of its own.
	if (kelurahan !== null && (kelurahan === kecamatan || kelurahan === kota)) {
		kelurahan = null;
	}
	if (kecamatan !== null && kecamatan === kota) {
		kecamatan = null;
	}

	if (kota === null) {
		// No city/regency at all: fall back to the province so the
		// place still has a name.
		kota = addressPart(district, ['state']) ?? addressPart(fine, ['state']);
	}

	if (kota === null) {
		return null;
	}

	const specific = kelurahan ?? kecamatan;
	const name = specific !== null ? `${specific}, ${kota}` : kota;
	const fullName = [kelurahan, kecamatan, kota].filter((part) => part !== null && part !== '').join(', ');

	const details: ReverseGeocodeDetails = { name, fullName, kelurahan, kecamatan, kota, latitude, longitude };
	reverseCache.set(key, details);
	return details;
}
