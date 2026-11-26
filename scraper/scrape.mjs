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
 *  - Galeri 24 + UBS 1-gram prices: galeri24.co.id/harga-emas — the
 *    page is server-rendered, one price table per brand ("Harga
 *    GALERI 24", "Harga UBS", "Harga ANTAM"), each row a gramage
 *    with sell + buyback price. Pegadaian's own site is
 *    bot-protected (403 + empty in headless Chrome), so the
 *    Pegadaian 1-gram price comes from the Logam Mulia backup CSV
 *    (pegadaian.csv) instead — only the latest date's 0.01-gram
 *    row, scaled x100 to 1 gram. EmasKita has no public source and
 *    stays manual in the theme.
 *  - Gold spot per gram (IDR): xaus.com public API, no key.
 *
 * Only the important data is taken: the 1-gram sell price per
 * brand (what the Gold widget shows) + spot. No other gramages,
 * no buyback, no history — the CSV is read for its latest row
 * only.
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
 * Galeri 24 + UBS 1-gram sell prices from galeri24.co.id/harga-emas.
 * The page is server-rendered: one table per brand, headed
 * "Harga GALERI 24" / "Harga UBS", each row "gramage | sell | buyback".
 * Returns { galeri24, ubs } (each a number or null) — a brand whose
 * table or 1-gram row is missing returns null, never a guess.
 */
async function galeri24Prices() {
	const html = await fetchText('https://galeri24.co.id/harga-emas');
	const out = { galeri24: null, ubs: null };

	for (const [key, brand] of [['galeri24', 'GALERI 24'], ['ubs', 'UBS']]) {
		const head = html.indexOf(`Harga ${brand}`);
		if (head === -1) continue;
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
		const row = segment.match(/>1<\/div><div[^>]*>Rp([0-9.]+)<\/div>/);
		if (row) out[key] = parseIdr(row[1]);
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

	const prices = {};
	const sources = {};

	// Antam 1g (brand price, scraped).
	const antam = await antamPrice().catch((err) => {
		console.error('antam failed:', err.message);
		return null;
	});
	if (antam !== null) {
		prices.antam = { price: antam, currency: 'IDR', updated: now };
		sources.antam = 'harga-emas.net';
	} else if (prevPrices.antam?.price) {
		prices.antam = { ...prevPrices.antam };
		sources.antam = prev?.sources?.antam ?? 'harga-emas.net (stale)';
	}

	// Galeri 24 + UBS 1g sell prices (brand prices, scraped).
	const galeri = await galeri24Prices().catch((err) => {
		console.error('galeri24 failed:', err.message);
		return { galeri24: null, ubs: null };
	});
	for (const [key, symbol, source] of [
		['galeri24', 'galeri-24', 'galeri24.co.id'],
		['ubs', 'ubs', 'galeri24.co.id'],
	]) {
		if (galeri[key] !== null) {
			prices[symbol] = { price: galeri[key], currency: 'IDR', updated: now };
			sources[symbol] = source;
		} else if (prevPrices[symbol]?.price) {
			prices[symbol] = { ...prevPrices[symbol] };
			sources[symbol] = prev?.sources?.[symbol] ?? `${source} (stale)`;
		}
	}

	// Gold spot per gram (IDR).
	const spot = await spotPrice().catch((err) => {
		console.error('spot failed:', err.message);
		return null;
	});
	if (spot !== null) {
		prices.spot = { price: Math.round(spot), currency: 'IDR', updated: now };
		sources.spot = 'xaus.com';
	} else if (prevPrices.spot?.price) {
		prices.spot = { ...prevPrices.spot };
		sources.spot = prev?.sources?.spot ?? 'xaus.com (stale)';
	}

	// Pegadaian 1g (brand price, from the Logam Mulia backup CSV —
	// Pegadaian's own site blocks scrapers).
	const pegadaian = await pegadaianPrice().catch((err) => {
		console.error('pegadaian failed:', err.message);
		return null;
	});
	if (pegadaian !== null) {
		prices.pegadaian = { price: Math.round(pegadaian), currency: 'IDR', updated: now };
		sources.pegadaian = 'logam-mulia-api (pegadaian.csv)';
	} else if (prevPrices.pegadaian?.price) {
		prices.pegadaian = { ...prevPrices.pegadaian };
		sources.pegadaian = prev?.sources?.pegadaian ?? 'logam-mulia-api (stale)';
	}

	if (Object.keys(prices).length === 0) {
		console.error('no prices at all — refusing to write an empty file');
		process.exit(1);
	}

	const out = {
		updated: now,
		prices,
		sources,
		note: 'Free, self-hosted gold prices for the Koperasi Kita theme. Antam is the 1-gram retail price scraped from harga-emas.net; Galeri 24 and UBS are the 1-gram sell prices scraped from galeri24.co.id; Pegadaian is the 1-gram price from the Logam Mulia backup CSV (pegadaian.csv, latest date only); spot is the live XAU price per gram in IDR from xaus.com. EmasKita stays manual — it has no public source.',
	};

	writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n', 'utf8');
	console.log(`wrote ${OUT}:`, JSON.stringify(prices));

	const recorded = appendHistory(prices);
	console.log(recorded ? `appended ${HISTORY}` : 'no price change — history untouched');
}

main().catch((err) => {
	console.error(err);
	process.exit(1);
});
