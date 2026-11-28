/**
 * Koperasi Kita — gold prices scraper.
 *
 * Runs in GitHub Actions (see .github/workflows/gold-prices.yml) on a
 * schedule and writes scraper/prices.json. The WordPress theme reads
 * that JSON (raw.githubusercontent.com) as a free, self-hosted API
 * for the gold prices no public API provides.
 *
 * Sources (all free, no key):
 *  - Antam 1-gram price: harga-emas.net — the 1-gram Antam price is
 *    in the page's meta description ("Antam 1 gr: Rp. 2.501.000").
 *    That page carries no buyback, so the Antam buyback comes from
 *    the galeri24.co.id ANTAM table (same page as below) and, when
 *    that is missing too, from the Logam Mulia hargaemas-net.csv.
 *  - Galeri 24 + UBS + Lotus Archi 1-gram prices:
 *    galeri24.co.id/harga-emas — the page is server-rendered, one
 *    price table per brand ("Harga GALERI 24", "Harga UBS", "Harga
 *    LOTUS ARCHI", "Harga ANTAM", ...), each row a gramage with sell
 *    + buyback price. Both halves are taken.
 *  - Hartadinata 1-gram buy price: the official hrtagold.id API
 *    (GET /api/v1/brandings/price/daily, no key) — the "Gold"
 *    series, gramasi 1, `price` field, and `buyback_price` for the
 *    buyback. The "Prime" series is a different EMASKU line and is
 *    never used. The meta description ("Beli: Rp 2.275.000 | Jual
 *    Rp 2.139.000") is the fallback when the API is unreachable or
 *    its shape is unexpected — Beli for the price, Jual for the
 *    buyback.
 *  - King Halim 1-gram sell price: kinghalim.com/goldbarwithamala
 *    — the "1 Gr" card's price, and the "Harga Buyback" line for
 *    the buyback.
 *    Prices there use US formatting with a ".00" decimal, so they
 *    are parsed as floats, not with parseIdr.
 *    EmasKita is not a brand in the theme — it is Hartadinata's
 *    Antam-collab micro-bar line; the theme lists Hartadinata as
 *    Emasku (HRTA GOLD) instead — so it is not scraped here.
 *    Mini gold (MiniGold, EOA Gold, IGS Gold, Waris Sampoerna) is
 *    a separate category — small denominations, not gram bars —
 *    so it is not scraped here.
 *  - Gold spot per gram (IDR): xaus.com public API, no key.
 *
 * Only the important data is taken: the 1-gram sell price per
 * brand + its buyback (what the Gold widget and the Data Desk buyback
 * stat show) + spot. No other gramages, no history — the CSV is read
 * for its latest row only.
 *
 * Fallback rule: when one half of a brand (sell or buyback) is not
 * obtained from its primary source, the alternative source is called
 * too — the Logam Mulia backup CSVs (the same files the theme's PHP
 * sync imports) for Galeri 24 / UBS / Lotus Archi / Antam, the
 * galeri24.co.id ANTAM table for the Antam buyback, the HRTA meta
 * description for Hartadinata. A half no source yields keeps the
 * last good value (with its real timestamp) instead of a zero.
 *
 * The script never invents a price: a source that fails or returns
 * no usable number is skipped, and the JSON keeps the last good
 * value for that symbol (with its real timestamp) instead of a
 * zero. It exits non-zero only when NO price at all could be
 * produced, so the workflow fails loudly instead of publishing
 * an empty file.
 *
 * Usage: node scraper/scrape.mjs
 * Node >= 18 (global fetch). No dependencies.
 */

import { appendFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = dirname(fileURLToPath(import.meta.url));
const OUT = join(DIR, 'prices.json');
const HISTORY = join(DIR, 'history.jsonl');
const HISTORY_DAYS = 90;
const UA = 'Mozilla/5.0 (compatible; KoperasiKita-GoldScraper/1.0; +https://github.com/ArrayIterator/koperasikita-theme)';

/** Parse "2.501.000" / "2,501,000" / "2501000" into a number, or null. */
function parseIdr(raw) {
	if (typeof raw !== 'string') return null;
	const cleaned = raw.replace(/[^0-9.,]/g, '').replace(/,/g, '').replace(/\./g, '');
	// Indonesian thousands use dots: "2.501.000" -> strip dots.
	const digits = raw.replace(/[^0-9]/g, '');
	if (digits === '') return null;
	const value = Number(digits);
	return Number.isFinite(value) && value > 0 ? value : null;
}

/**
 * A 1-gram IDR price must be plausible (500 ribu – 10 juta):
 * catches a wrong-row parse (e.g. a 100-gram price) without ever
 * inventing a value. Out-of-band means "no usable number", so the
 * caller keeps the last good value.
 */
function saneGramPrice(value) {
	if (typeof value !== 'number' || !Number.isFinite(value)) return null;
	if (value < 500000 || value > 10000000) return null;
	return Math.round(value);
}

async function fetchText(url) {
	const res = await fetch(url, {
		headers: { 'User-Agent': UA, Accept: 'text/html,application/json' },
		signal: AbortSignal.timeout(15000),
	});
	if (!res.ok) throw new Error(`HTTP ${res.status} for ${url}`);
	return res.text();
}

/** Antam 1-gram price from harga-emas.net's meta description. */
async function antamPrice() {
	const html = await fetchText('https://harga-emas.net/');
	const meta = html.match(/Antam 1 gr:\s*Rp\.?\s*([0-9.,]+)/i);
	if (!meta) return null;
	return parseIdr(meta[1]);
}

/**
 * Parse one brand table out of the galeri24.co.id/harga-emas HTML:
 * the 1-gram row carries the sell price first and the buyback price
 * second (">1</div><div>Rp sell</div><div>Rp buyback</div>").
 * Returns { sell, buyback } (each a number or null) — a missing
 * table or row yields nulls, and a missing second price yields a
 * null buyback, never a guess.
 */
function parseGaleri24Table(html, brand) {
	const head = html.indexOf(`Harga ${brand}`);
	if (head === -1) return { sell: null, buyback: null };
	// The next table starts at the following brand heading
	// ("Harga GALERI 24", "Harga ANTAM", ... — all-caps brand
	// name). The column headers "Harga Jual" / "Harga Buyback"
	// are title case, so they never match [A-Z]{2,} and cannot
	// cut the segment short. Only scan until that next heading
	// so a later table's 1-gram row can never be mistaken for
	// this brand's.
	const rest = html.slice(head + 1);
	const nextBrand = rest.search(/Harga [A-Z]{2,}/);
	const segment = nextBrand === -1 ? rest : rest.slice(0, nextBrand);
	const row = segment.match(/>1<\/div><div[^>]*>Rp([0-9.]+)<\/div>(?:<div[^>]*>Rp([0-9.]+)<\/div>)?/);
	if (!row) return { sell: null, buyback: null };
	return { sell: saneGramPrice(parseIdr(row[1])), buyback: row[2] ? saneGramPrice(parseIdr(row[2])) : null };
}

/**
 * Galeri 24 + UBS + Lotus Archi + Antam 1-gram sell + buyback
 * prices from galeri24.co.id/harga-emas. The page is
 * server-rendered: one table per brand, headed "Harga GALERI 24" /
 * "Harga UBS" / "Harga LOTUS ARCHI" / "Harga ANTAM", each row
 * "gramage | sell | buyback". Returns per brand { sell, buyback }
 * (each a number or null) — a brand whose table or 1-gram row is
 * missing returns nulls, never a guess. The plain LOTUS ARCHI
 * table comes before LOTUS ARCHI GIFT, so the first "Harga LOTUS
 * ARCHI" match is the plain table. The ANTAM table is the
 * alternative for the Antam buyback (harga-emas.net carries no
 * buyback) and for the Antam sell when harga-emas.net fails.
 */
async function galeri24Prices() {
	const html = await fetchText('https://galeri24.co.id/harga-emas');
	const out = {};

	for (const [key, brand] of [
		['galeri24', 'GALERI 24'],
		['ubs', 'UBS'],
		['lotusArchi', 'LOTUS ARCHI'],
		['antam', 'ANTAM'],
	]) {
		out[key] = parseGaleri24Table(html, brand);
	}

	return out;
}

/** Gold spot per gram in IDR from xaus.com (no key). */
async function spotPrice() {
	const res = await fetch('https://xaus.com/api/v1/spot?currency=IDR&unit=gram', {
		headers: { 'User-Agent': UA, Accept: 'application/json' },
		signal: AbortSignal.timeout(15000),
	});
	if (!res.ok) throw new Error(`HTTP ${res.status} for xaus.com`);
	const data = await res.json();
	const price = data?.xau?.price;
	return typeof price === 'number' && price > 0 ? price : null;
}

/** Split one CSV line into fields, honouring double-quoted fields. */
function parseCsvLine(line) {
	const out = [];
	let cur = '';
	let quoted = false;
	for (let i = 0; i < line.length; i++) {
		const ch = line[i];
		if (ch === '"') {
			if (quoted && line[i + 1] === '"') {
				cur += '"';
				i++;
			} else {
				quoted = !quoted;
			}
		} else if (ch === ',' && !quoted) {
			out.push(cur);
			cur = '';
		} else {
			cur += ch;
		}
	}
	out.push(cur);
	return out;
}

/**
 * Pegadaian 1-gram price from the Logam Mulia backup CSV
 * (pegadaian.csv). Pegadaian's own site blocks scrapers, so this
 * is the only live source. Only the important data is taken: the
 * file's latest recordedDate, weight 0.01 gram, scaled x100 to
 * 1 gram. The rest of the file (older dates, buyback) is ignored.
 * Returns the 1-gram price, or null when the file has no usable
 * latest row.
 *
 * Unused: Pegadaian is not a brand in the theme, so main() never
 * calls this; kept for a later cleanup decision.
 */
async function pegadaianPrice() {
	const text = await fetchText(
		'https://raw.githubusercontent.com/iamutaki/logam-mulia-api/main/backup/prices/pegadaian.csv',
	);
	const lines = text.split('\n').filter((l) => l.trim() !== '');
	if (lines.length < 2) return null;

	const header = parseCsvLine(lines[0]);
	const iWeight = header.indexOf('weight');
	const iSell = header.indexOf('sellPrice');
	const iDate = header.indexOf('recordedDate');
	const iMaterial = header.indexOf('material');
	if (iWeight === -1 || iSell === -1 || iDate === -1) return null;

	let maxDate = '';
	let price = null;
	for (const line of lines.slice(1)) {
		const row = parseCsvLine(line);
		const need = Math.max(iWeight, iSell, iDate);
		if (row.length <= need) continue;
		if (iMaterial !== -1 && row[iMaterial] !== 'gold') continue;
		const date = row[iDate];
		if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
		if (Number(row[iWeight]) !== 0.01) continue;
		const sell = Number(row[iSell]);
		if (!Number.isFinite(sell) || sell <= 0) continue;
		// Rows are append-only in date order; a later date wins.
		if (date >= maxDate) {
			maxDate = date;
			price = sell * 100;
		}
	}

	return price;
}

/**
 * Parse the Hartadinata API payload: the "Gold" series, gramasi 1,
 * `price` for the buy price and `buyback_price` for the buyback.
 * Returns { price, buyback } (each a number or null).
 */
function parseHartadinataApi(data) {
	const series = Array.isArray(data?.data) ? data.data.find((s) => s?.series === 'Gold') : null;
	const row = Array.isArray(series?.prices) ? series.prices.find((p) => p?.gramasi === 1) : null;
	if (!row) return { price: null, buyback: null };
	const price = typeof row.price === 'number' ? saneGramPrice(row.price) : null;
	const buyback = typeof row.buyback_price === 'number' ? saneGramPrice(row.buyback_price) : null;
	return { price, buyback };
}

/**
 * Parse the Hartadinata gold-price page: the meta description
 * ("Beli: Rp ... | Jual Rp ...") first, then the headline
 * ("Harga Emas Hari Ini: Rp ...") for the price when Beli is
 * missing. Beli is the buy price, Jual is the buyback. Returns
 * { price, buyback } (each a number or null).
 */
function parseHartadinataMeta(html) {
	const meta = html.match(/Beli:\s*Rp\s*([0-9.]+)/i);
	const jual = html.match(/Jual[:\s]*Rp\s*([0-9.]+)/i);
	const price = meta ? saneGramPrice(parseIdr(meta[1])) : null;
	const buyback = jual ? saneGramPrice(parseIdr(jual[1])) : null;
	if (price !== null) return { price, buyback };
	const head = html.match(/Harga Emas Hari Ini:\s*Rp\s*([0-9.]+)/i);
	if (!head) return { price: null, buyback };
	return { price: saneGramPrice(parseIdr(head[1])), buyback };
}

/**
 * Hartadinata (HRTA) 1-gram prices from the official API
 * (GET https://hrtagold.id/api/v1/brandings/price/daily, no key):
 * the "Gold" series, gramasi 1, `price` field for the buy price
 * and `buyback_price` for the buyback. The "Prime" series
 * is a different EMASKU line and is never used. Returns
 * { price, buyback } (each a number or null) — null when the API
 * is unreachable or its shape is unexpected.
 */
async function hartadinataApiPrice() {
	const res = await fetch('https://hrtagold.id/api/v1/brandings/price/daily', {
		headers: { 'User-Agent': UA, Accept: 'application/json' },
		signal: AbortSignal.timeout(15000),
	});
	if (!res.ok) return null;
	const data = await res.json();
	return parseHartadinataApi(data);
}

/**
 * Hartadinata (HRTA) 1-gram prices: the official API first, the
 * meta description ("Beli: Rp 2.275.000 | Jual Rp 2.139.000.
 * Update terakhir 27/11/2026") and then the headline ("Harga Emas
 * Hari Ini: Rp 2.275.000") as fallback. Beli is the buy price,
 * Jual is the buyback. Returns { price, buyback } (each a number
 * or null) — nulls when neither source yields a plausible price,
 * so the caller keeps the last good value.
 */
async function hartadinataPrice() {
	const api = await hartadinataApiPrice().catch(() => null);
	if (api !== null) return api;
	const html = await fetchText('https://hrtagold.id/en/gold-price');
	return parseHartadinataMeta(html);
}

/**
 * Parse the King Halim page: the "1 Gr" card's price for the sell
 * and the "Harga Buyback : Rp ... / Gr" line for the buyback.
 * Prices there use US formatting with a ".00" decimal, so they
 * are parsed as floats, not with parseIdr (which would read
 * the ".00" as more digits). Returns { sell, buyback } (each a
 * number or null).
 */
function parseKingHalim(html) {
	const head = html.indexOf('>1 Gr<');
	if (head === -1) return { sell: null, buyback: null };
	const row = html.slice(head, head + 2000).match(/Rp\s*([0-9,]+(?:\.[0-9]{2})?)/);
	const sell = row ? saneGramPrice(Number(row[1].replace(/,/g, ''))) : null;
	const bb = html.match(/Harga Buyback\s*:\s*Rp\s*([0-9,]+(?:\.[0-9]{2})?)/);
	const buyback = bb ? saneGramPrice(Number(bb[1].replace(/,/g, ''))) : null;
	return { sell, buyback };
}

/**
 * King Halim 1-gram sell + buyback prices from
 * kinghalim.com/goldbarwithamala. The page lists one card per
 * weight: the "1 Gr" heading followed by its "Rp 2,275,000.00"
 * price, and a "Harga Buyback : Rp 2,140,000.00 / Gr" line for the
 * buyback. Returns { sell, buyback } (each a number or null) —
 * nulls when the 1-gram card or its price is missing, or the
 * value is implausible.
 */
async function kingHalimPrice() {
	const html = await fetchText('https://kinghalim.com/goldbarwithamala');
	return parseKingHalim(html);
}

const LM_CSV_BASE = 'https://raw.githubusercontent.com/iamutaki/logam-mulia-api/main/backup/prices';

/**
 * Parse one Logam Mulia backup CSV into the latest 1-gram rows:
 * a map materialType => { sell, buyback, date } for the rows at
 * the file's latest recordedDate (sellPrice/buybackPrice are plain
 * numbers; a 0 means "no data" and yields null for that half).
 * The files are append-only history; only the newest date counts.
 */
function parseLogamMuliaCsv(text) {
	const lines = text.split('\n').filter((l) => l.trim() !== '');
	if (lines.length < 2) return {};
	const header = parseCsvLine(lines[0]);
	const at = (name) => header.indexOf(name);
	const iType = at('materialType');
	const iWeight = at('weight');
	const iSell = at('sellPrice');
	const iBuy = at('buybackPrice');
	const iDate = at('recordedDate');
	const iMat = at('material');
	if ([iType, iWeight, iSell, iBuy, iDate].some((x) => x === -1)) return {};

	let maxDate = '';
	const rows = [];
	for (const line of lines.slice(1)) {
		const row = parseCsvLine(line);
		if (row.length <= Math.max(iType, iWeight, iSell, iBuy, iDate)) continue;
		if (iMat !== -1 && row[iMat] !== 'gold') continue;
		const date = row[iDate];
		if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue;
		if (Number(row[iWeight]) !== 1) continue;
		rows.push(row);
		if (date > maxDate) maxDate = date;
	}

	if (maxDate === '') return {};
	const out = {};
	for (const row of rows) {
		if (row[iDate] !== maxDate) continue;
		const type = row[iType];
		if (out[type]) continue;
		const sell = Number(row[iSell]);
		const buy = Number(row[iBuy]);
		out[type] = {
			sell: Number.isFinite(sell) && sell > 0 ? sell : null,
			buyback: Number.isFinite(buy) && buy > 0 ? buy : null,
			date: maxDate,
		};
	}
	return out;
}

/**
 * The alternative source for Galeri 24 / UBS / Lotus Archi /
 * Antam: the Logam Mulia backup CSVs (the same files the theme's
 * PHP sync imports). Only called when a primary half is missing —
 * the 1.5 MB galeri24.csv is never downloaded on a clean run.
 * Returns per id { sell, buyback, updated, source } (null when
 * that file or row is missing); `updated` is the CSV row's
 * recordedDate, so an alternative-sourced price keeps its real
 * date instead of pretending to be fresh.
 */
async function logamMuliaCsvPrices() {
	const [galeri, hargaemas] = await Promise.all([
		fetchText(`${LM_CSV_BASE}/galeri24.csv`).catch(() => null),
		fetchText(`${LM_CSV_BASE}/hargaemas-net.csv`).catch(() => null),
	]);
	const out = {
		'galeri-24': null,
		ubs: null,
		'lotus-archi': null,
		'antam-g24': null,
		'antam-he': null,
	};
	const take = (parsed, type, source) => {
		const row = parsed[type];
		if (!row) return null;
		return {
			sell: row.sell,
			buyback: row.buyback,
			updated: `${row.date}T00:00:00.000Z`,
			source,
		};
	};
	if (galeri !== null) {
		const parsed = parseLogamMuliaCsv(galeri);
		out['galeri-24'] = take(parsed, 'GALERI 24', 'logam-mulia-api galeri24.csv');
		out.ubs = take(parsed, 'UBS', 'logam-mulia-api galeri24.csv');
		out['lotus-archi'] = take(parsed, 'LOTUS ARCHI', 'logam-mulia-api galeri24.csv');
		out['antam-g24'] = take(parsed, 'ANTAM', 'logam-mulia-api galeri24.csv');
	}
	if (hargaemas !== null) {
		const parsed = parseLogamMuliaCsv(hargaemas);
		out['antam-he'] = take(parsed, 'Emas LM Batangan Antam', 'logam-mulia-api hargaemas-net.csv');
	}
	return out;
}

/**
 * Resolve one brand's sell + buyback from its primary result, the
 * alternative results (in priority order) and the previous (last
 * good) entry. Each half is taken from the first candidate that
 * has it; a half no candidate has keeps the last good value; a
 * half nothing has is null. Nothing is ever invented.
 *
 * A candidate is { sell, buyback, source, updated } or null; prev
 * is the previous prices.json entry { price, buyback, updated }.
 * Returns { sell, buyback, updated, source } — `updated` is the
 * sell's own timestamp (a stale sell keeps its real timestamp);
 * `source` names the sell's origin (" (alternative)" when it came
 * from an alternative, "(stale)" when it is the last good value),
 * plus " (buyback: <source>)" when the buyback came from a
 * different source than the sell.
 */
function resolveBrand(primary, alternatives, prev, prevSource, now) {
	const cands = [primary, ...alternatives.filter((c) => c !== null && c !== undefined)];
	const pick = (field, prevField) => {
		for (const c of cands) {
			const v = c[field];
			if (v !== null && v !== undefined) {
				return { value: v, source: c.source, updated: c.updated, alt: c !== primary, prev: false };
			}
		}
		const pv = prev?.[prevField];
		if (pv !== null && pv !== undefined) {
			return { value: pv, source: null, updated: prev?.updated ?? null, alt: false, prev: true };
		}
		return { value: null, source: null, updated: null, alt: false, prev: false };
	};
	const sell = pick('sell', 'price');
	const buyback = pick('buyback', 'buyback');
	if (sell.value === null) return { sell: null, buyback: null, updated: null, source: null };

	let source;
	if (sell.prev) source = prevSource ?? `${primary.source} (stale)`;
	else source = sell.alt ? `${sell.source} (alternative)` : sell.source;
	if (buyback.value !== null && buyback.source !== null && buyback.source !== sell.source) {
		source += ` (buyback: ${buyback.source})`;
	}
	return {
		sell: sell.value,
		buyback: buyback.value,
		updated: sell.updated ?? prev?.updated ?? now,
		source,
	};
}

function readPrevious() {
	try {
		const raw = readFileSync(OUT, 'utf8');
		const prev = JSON.parse(raw);
		return prev && typeof prev === 'object' ? prev : null;
	} catch {
		return null;
	}
}

/**
 * History record (history.jsonl, one JSON object per line).
 * A record is appended only when a price actually changed versus
 * the last record, so the file is a clean change-history instead
 * of the same value repeated every 15 minutes. Entries older than
 * HISTORY_DAYS are pruned, so the file never grows without bound.
 */
function readHistory() {
	try {
		if (!existsSync(HISTORY)) return [];
		return readFileSync(HISTORY, 'utf8')
			.split('\n')
			.filter((line) => line.trim() !== '')
			.map((line) => JSON.parse(line));
	} catch {
		return [];
	}
}

function appendHistory(prices) {
	const now = new Date();
	const record = { ts: now.toISOString() };
	for (const [symbol, entry] of Object.entries(prices)) {
		record[symbol] = entry.price;
	}

	const history = readHistory();
	const last = history[history.length - 1];
	const changed =
		!last || Object.keys(record).some((key) => key !== 'ts' && last[key] !== record[key]);

	if (!changed) {
		return false;
	}

	const cutoff = now.getTime() - HISTORY_DAYS * 24 * 60 * 60 * 1000;
	const kept = history.filter((row) => {
		const ts = Date.parse(row.ts ?? '');
		return Number.isFinite(ts) && ts >= cutoff;
	});
	kept.push(record);
	writeFileSync(HISTORY, kept.map((row) => JSON.stringify(row)).join('\n') + '\n', 'utf8');
	return true;
}

async function main() {
	const now = new Date().toISOString();
	const prev = readPrevious();
	const prevPrices = prev?.prices && typeof prev.prices === 'object' ? prev.prices : {};
	const prevSources = prev?.sources && typeof prev.sources === 'object' ? prev.sources : {};

	// Lazy alternative: the Logam Mulia CSVs are only fetched when a
	// primary half is missing — a clean run never downloads them.
	let lmCache = null;
	async function lmPrices() {
		if (lmCache === null) {
			lmCache = await logamMuliaCsvPrices().catch((err) => {
				console.error('logam-mulia csv failed:', err.message);
				return null;
			});
		}
		return lmCache;
	}

	const prices = {};
	const sources = {};

	// Galeri 24 + UBS + Lotus Archi + Antam table from the live page.
	const galeri = await galeri24Prices().catch((err) => {
		console.error('galeri24 failed:', err.message);
		return {
			galeri24: { sell: null, buyback: null },
			ubs: { sell: null, buyback: null },
			lotusArchi: { sell: null, buyback: null },
			antam: { sell: null, buyback: null },
		};
	});

	// Galeri 24 + UBS + Lotus Archi 1g sell + buyback (scraped).
	for (const [key, symbol] of [
		['galeri24', 'galeri-24'],
		['ubs', 'ubs'],
		['lotusArchi', 'lotus-archi'],
	]) {
		const primary = { sell: galeri[key].sell, buyback: galeri[key].buyback, source: 'galeri24.co.id', updated: now };
		const alternatives = [];
		if (primary.sell === null || primary.buyback === null) {
			const lm = await lmPrices();
			if (lm?.[symbol]) alternatives.push(lm[symbol]);
		}
		const resolved = resolveBrand(primary, alternatives, prevPrices[symbol], prevSources[symbol], now);
		if (resolved.sell === null) continue;
		prices[symbol] = { price: resolved.sell, buyback: resolved.buyback, currency: 'IDR', updated: resolved.updated };
		sources[symbol] = resolved.source;
	}

	// Gold spot per gram (IDR).
	const spot = await spotPrice().catch((err) => {
		console.error('spot failed:', err.message);
		return null;
	});
	if (spot !== null) {
		prices.spot = { price: Math.round(spot), buyback: null, currency: 'IDR', updated: now };
		sources.spot = 'xaus.com';
	} else if (prevPrices.spot?.price) {
		prices.spot = { ...prevPrices.spot, buyback: prevPrices.spot.buyback ?? null };
		sources.spot = prevSources.spot ?? 'xaus.com (stale)';
	}

	// Antam 1g (scraped): the sell from harga-emas.net, the buyback
	// from the galeri24.co.id ANTAM table (harga-emas.net carries
	// no buyback), the Logam Mulia CSVs as the next alternative.
	const antamSell = await antamPrice().catch((err) => {
		console.error('antam failed:', err.message);
		return null;
	});
	{
		const primary = { sell: antamSell, buyback: null, source: 'harga-emas.net', updated: now };
		const alternatives = [];
		if (galeri.antam.sell !== null || galeri.antam.buyback !== null) {
			alternatives.push({ sell: galeri.antam.sell, buyback: galeri.antam.buyback, source: 'galeri24.co.id', updated: now });
		}
		if ((primary.sell === null && galeri.antam.sell === null) || galeri.antam.buyback === null) {
			const lm = await lmPrices();
			if (lm?.['antam-g24']) alternatives.push(lm['antam-g24']);
			if (lm?.['antam-he']) alternatives.push(lm['antam-he']);
		}
		const resolved = resolveBrand(primary, alternatives, prevPrices.antam, prevSources.antam, now);
		if (resolved.sell !== null) {
			prices.antam = { price: resolved.sell, buyback: resolved.buyback, currency: 'IDR', updated: resolved.updated };
			sources.antam = resolved.source;
		}
	}

	// Hartadinata (HRTA) 1g buy + buyback (scraped): the official
	// API first, the meta description (Beli + Jual) as fallback.
	const hartadinata = await hartadinataPrice().catch((err) => {
		console.error('hartadinata failed:', err.message);
		return { price: null, buyback: null };
	});
	{
		const primary = { sell: hartadinata.price, buyback: hartadinata.buyback, source: 'hrtagold.id', updated: now };
		const resolved = resolveBrand(primary, [], prevPrices.hartadinata, prevSources.hartadinata, now);
		if (resolved.sell !== null) {
			prices.hartadinata = { price: resolved.sell, buyback: resolved.buyback, currency: 'IDR', updated: resolved.updated };
			sources.hartadinata = resolved.source;
		}
	}

	// King Halim 1g sell + buyback (scraped).
	const kingHalim = await kingHalimPrice().catch((err) => {
		console.error('king-halim failed:', err.message);
		return { sell: null, buyback: null };
	});
	{
		const primary = { sell: kingHalim.sell, buyback: kingHalim.buyback, source: 'kinghalim.com', updated: now };
		const resolved = resolveBrand(primary, [], prevPrices['king-halim'], prevSources['king-halim'], now);
		if (resolved.sell !== null) {
			prices['king-halim'] = { price: resolved.sell, buyback: resolved.buyback, currency: 'IDR', updated: resolved.updated };
			sources['king-halim'] = resolved.source;
		}
	}

	if (Object.keys(prices).length === 0) {
		console.error('no prices at all — refusing to write an empty file');
		process.exit(1);
	}

	const out = {
		updated: now,
		prices,
		sources,
		note: 'Free, self-hosted gold prices for the Koperasi Kita theme. Antam is the 1-gram retail price scraped from harga-emas.net, its buyback from the galeri24.co.id ANTAM table (Logam Mulia hargaemas-net.csv as the next alternative); Galeri 24, UBS and Lotus Archi are the 1-gram sell + buyback prices scraped from galeri24.co.id (Logam Mulia galeri24.csv as the alternative when a half is missing); Hartadinata is the 1-gram buy price + buyback from the official hrtagold.id API (HTML meta as fallback); King Halim is the 1-gram sell + buyback price scraped from kinghalim.com; spot is the live XAU price per gram in IDR from xaus.com. Mini gold is a separate category and is not scraped here.',
	};

	writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n', 'utf8');
	console.log(`wrote ${OUT}:`, JSON.stringify(prices));

	const recorded = appendHistory(prices);
	console.log(recorded ? `appended ${HISTORY}` : 'no price change — history untouched');
}

// The parsers and the merge are pure, so the harness
// (kk-verify-gold-sync-fallback/test-scraper.mjs) can test them
// against saved live fixtures without running the live scrape on
// import. main() only runs when the file is executed directly
// (node scraper/scrape.mjs), exactly as the workflow does.
export { parseIdr, saneGramPrice, parseGaleri24Table, parseLogamMuliaCsv, parseHartadinataApi, parseHartadinataMeta, parseKingHalim, resolveBrand };

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
	main().catch((err) => {
		console.error(err);
		process.exit(1);
	});
}
