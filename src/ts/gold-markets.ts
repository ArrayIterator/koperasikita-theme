/**
 * Koperasi Kita - gold prices slider and markets strip.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when the Markets feature is enabled (window.kkData.isMarketsEnabled);
 * a disabled feature loads neither this script nor any markets markup,
 * so there is nothing to guard against beyond the flag.
 *
 * The gold slider is a track slider modelled on the demo's vertical
 * gold carousel: the slides sit in a flex track inside an
 * overflow-hidden viewport and the track translates by whole steps
 * (translateY for vertical, translateX for horizontal). The first
 * slides are cloned onto the end so the loop wraps without a visible
 * seam, and the wrap-back is a jump with the transition disabled.
 * The markets slider keeps the older single-slide toggle.
 * Both auto-advance on a timer, pause on hover/focus, when the tab
 * is hidden, and when the user presses pause. Prev/next wrap
 * around, dots jump to a slide, and a horizontal swipe changes slides -
 * the controls are real buttons with 44px touch targets for Android.
 * A slider with a single slide has no controls and no timer.
 */

export {};

import { postAjax } from './api';

interface MarketsKkData {
	isMarketsEnabled?: boolean;
	ajaxUrl?: string;
	nonce?: string;
}

function getKkData(): MarketsKkData {
	// Read straight off window instead of augmenting the global Window
	// type: main.ts and the other feature modules each declare their own
	// kkData shape, and re-declaring it here would break type merging.
	return (window as unknown as { kkData?: MarketsKkData }).kkData ?? {};
}

function $all<T extends Element>(selector: string, root: ParentNode = document): T[] {
	return Array.from(root.querySelectorAll<T>(selector));
}

/**
 * Track slider for the gold widget, all three styles. The slides sit
 * in a flex track inside the overflow-hidden .kk-widget-scroll
 * viewport and the track translates by whole steps: translateY for
 * the vertical carousel, translateX for the two horizontal styles.
 * This is the demo's vertical carousel mechanism: the first slides
 * are cloned onto the end of the track so the loop wraps without a
 * visible seam, advancing past the last real slide jumps back to
 * index 0 with the transition disabled, and going back from index 0
 * jumps to the end first and then animates one step back.
 *
 * One demo value is measured instead of hardcoded: the demo's cards
 * are a fixed 88px tall so its step is a constant 96, but theme
 * slides vary in height (brand + price + meta + an optional Updated
 * line, wrapping by width), so the step is the first slide's height
 * plus the track gap, read from the computed style, and the viewport
 * height is the bottom edge of the second slide, so exactly two
 * slides show. Both are re-measured on resize and on window load
 * (a late webfont can change the slide height).
 */
function initGoldSlider(slider: HTMLElement): void {
	const track = slider.querySelector<HTMLElement>('.kk-gold-slides');
	const viewport = slider.querySelector<HTMLElement>('.kk-widget-scroll');
	const slides = $all<HTMLElement>('[data-kk-gold-slide]', slider);
	if (track === null || slides.length <= 1) {
		return;
	}

	const total = slides.length;
	const dots = $all<HTMLButtonElement>('[data-kk-gold-dot]', slider);
	const prevButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-prev]');
	const nextButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-next]');
	const toggleButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-toggle]');
	const pauseIcon = toggleButton?.querySelector('.kk-widget-toggle-pause') as HTMLElement | null;
	const playIcon = toggleButton?.querySelector('.kk-widget-toggle-play') as HTMLElement | null;

	const parsedInterval = Number(slider.dataset.interval ?? 5000);
	const interval = Number.isFinite(parsedInterval) && parsedInterval >= 2000 ? parsedInterval : 5000;

	const style = slider.dataset.style ?? 'vertical-carousel';
	const vertical = style === 'vertical-carousel';
	const parsedPerView = Number(slider.dataset.itemsPerView ?? 1);
	const perView = vertical
		? 2
		: Number.isFinite(parsedPerView)
			? Math.max(1, Math.min(3, Math.floor(parsedPerView)))
			: 1;
	const cloneCount = Math.min(perView, total);
	const autoplay = slider.dataset.autoplay !== '0';
	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	for (let c = 0; c < cloneCount; c++) {
		const clone = slides[c].cloneNode(true) as HTMLElement;
		clone.setAttribute('aria-hidden', 'true');
		track.appendChild(clone);
	}

	let current = 0;
	let timer: number | null = null;
	let userPaused = false;
	let hoverPaused = false;
	let step = 0;

	const measure = (): void => {
		const gap = Number.parseFloat(window.getComputedStyle(track).gap) || 0;
		if (vertical) {
			step = slides[0].offsetHeight + gap;
			if (viewport !== null) {
				viewport.style.maxHeight = 'none';
				viewport.style.height = `${slides[0].offsetHeight + gap + slides[1].offsetHeight}px`;
			}
		} else {
			step = slides[0].offsetWidth + gap;
		}
	};

	function syncDots(active: number): void {
		dots.forEach((dot, dotIndex) => {
			const isActive = dotIndex === active;
			dot.classList.toggle('is-active', isActive);
			dot.setAttribute('aria-current', isActive ? 'true' : 'false');
		});
	}

	const go = (index: number, animate: boolean): void => {
		if (!animate || reducedMotion) {
			track.classList.add('kk-gold-track-flat');
		}
		track.style.transform = vertical
			? `translateY(${-index * step}px)`
			: `translateX(${-index * step}px)`;
		if (!animate || reducedMotion) {
			void track.offsetWidth;
			track.classList.remove('kk-gold-track-flat');
		}
		const active = ((index % total) + total) % total;
		slides.forEach((slide, slideIndex) => {
			slide.classList.toggle('is-active', slideIndex === active);
		});
		syncDots(active);
	};

	function next(): void {
		current += 1;
		go(current, true);
		if (current >= total) {
			window.setTimeout(() => {
				current = 0;
				go(0, false);
			}, 580);
		}
	}

	function prev(): void {
		if (current === 0) {
			current = total;
			go(total, false);
		}
		window.setTimeout(() => {
			current -= 1;
			go(current, true);
		}, 30);
	}

	function stop(): void {
		if (timer !== null) {
			window.clearInterval(timer);
			timer = null;
		}
	}

	function start(): void {
		if (!autoplay || userPaused || hoverPaused || document.hidden) {
			return;
		}
		stop();
		timer = window.setInterval(next, interval);
	}

	function syncToggle(): void {
		if (!toggleButton) {
			return;
		}
		toggleButton.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
		toggleButton.setAttribute('aria-label', userPaused ? 'Play gold prices' : 'Pause gold prices');
		if (pauseIcon) {
			pauseIcon.hidden = userPaused;
		}
		if (playIcon) {
			playIcon.hidden = !userPaused;
		}
	}

	prevButton?.addEventListener('click', () => {
		prev();
		start();
	});
	nextButton?.addEventListener('click', () => {
		next();
		start();
	});
	dots.forEach((dot, dotIndex) => {
		dot.addEventListener('click', () => {
			if (current >= total) {
				current %= total;
				go(current, false);
			}
			current = dotIndex;
			go(current, true);
			start();
		});
	});
	toggleButton?.addEventListener('click', () => {
		userPaused = !userPaused;
		syncToggle();
		if (userPaused) {
			stop();
		} else {
			start();
		}
	});

	slider.addEventListener('pointerenter', () => {
		hoverPaused = true;
		stop();
	});
	slider.addEventListener('pointerleave', () => {
		hoverPaused = false;
		start();
	});
	slider.addEventListener('focusin', () => {
		hoverPaused = true;
		stop();
	});
	slider.addEventListener('focusout', () => {
		hoverPaused = false;
		start();
	});

	document.addEventListener('visibilitychange', () => {
		if (document.hidden) {
			stop();
		} else {
			start();
		}
	});

	// Horizontal swipe: a short horizontal drag changes slides, a mostly
	// vertical drag is left alone so the page can still scroll.
	let touchStartX = 0;
	let touchStartY = 0;
	slider.addEventListener(
		'touchstart',
		(event) => {
			const touch = event.changedTouches[0];
			touchStartX = touch.clientX;
			touchStartY = touch.clientY;
		},
		{ passive: true },
	);
	slider.addEventListener(
		'touchend',
		(event) => {
			const touch = event.changedTouches[0];
			const deltaX = touch.clientX - touchStartX;
			const deltaY = touch.clientY - touchStartY;
			if (Math.abs(deltaX) < 40 || Math.abs(deltaX) < Math.abs(deltaY)) {
				return;
			}
			if (deltaX < 0) {
				next();
			} else {
				prev();
			}
			start();
		},
		{ passive: true },
	);

	measure();
	go(0, false);

	window.addEventListener('resize', () => {
		measure();
		go(current, false);
	});
	window.addEventListener('load', () => {
		measure();
		go(current, false);
	});

	if (!reducedMotion) {
		start();
	}
}

/**
 * Auto-advancing vertical slider for the markets slider. Slides are
 * [data-kk-markets-slide], dots [data-kk-markets-dot], controls
 * [data-kk-markets-prev/next/toggle]. One slide is shown at a time;
 * the active slide is toggled with the is-active class and the
 * kk-gold-slide-in keyframes.
 */
function initMarketsSlider(slider: HTMLElement): void {
	const slides = $all<HTMLElement>('[data-kk-markets-slide]', slider);
	if (slides.length <= 1) {
		return;
	}

	const dots = $all<HTMLButtonElement>('[data-kk-markets-dot]', slider);
	const prevButton = slider.querySelector<HTMLButtonElement>('[data-kk-markets-prev]');
	const nextButton = slider.querySelector<HTMLButtonElement>('[data-kk-markets-next]');
	const toggleButton = slider.querySelector<HTMLButtonElement>('[data-kk-markets-toggle]');
	const pauseIcon = toggleButton?.querySelector('.kk-widget-toggle-pause') as HTMLElement | null;
	const playIcon = toggleButton?.querySelector('.kk-widget-toggle-play') as HTMLElement | null;

	const parsedInterval = Number(slider.dataset.interval ?? 5000);
	const interval = Number.isFinite(parsedInterval) && parsedInterval >= 2000 ? parsedInterval : 5000;

	// data-autoplay="0" disables the timer.
	const autoplay = slider.dataset.autoplay !== '0';
	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	let current = 0;
	let timer: number | null = null;
	let userPaused = false;
	let hoverPaused = false;

	function syncDots(): void {
		dots.forEach((dot, dotIndex) => {
			const active = dotIndex === current;
			dot.classList.toggle('is-active', active);
			dot.setAttribute('aria-current', active ? 'true' : 'false');
		});
	}

	function show(index: number): void {
		const next = (index + slides.length) % slides.length;
		if (next === current) {
			return;
		}
		slides[current].classList.remove('is-active');
		current = next;
		slides[current].classList.add('is-active');
		syncDots();
	}

	function stop(): void {
		if (timer !== null) {
			window.clearInterval(timer);
			timer = null;
		}
	}

	function start(): void {
		if (!autoplay || userPaused || hoverPaused || document.hidden) {
			return;
		}
		stop();
		timer = window.setInterval(() => show(current + 1), interval);
	}

	function syncToggle(): void {
		if (!toggleButton) {
			return;
		}
		toggleButton.setAttribute('aria-pressed', userPaused ? 'true' : 'false');
		toggleButton.setAttribute('aria-label', userPaused ? 'Play markets' : 'Pause markets');
		if (pauseIcon) {
			pauseIcon.hidden = userPaused;
		}
		if (playIcon) {
			playIcon.hidden = !userPaused;
		}
	}

	prevButton?.addEventListener('click', () => {
		show(current - 1);
		start();
	});
	nextButton?.addEventListener('click', () => {
		show(current + 1);
		start();
	});
	dots.forEach((dot, dotIndex) => {
		dot.addEventListener('click', () => {
			show(dotIndex);
			start();
		});
	});
	toggleButton?.addEventListener('click', () => {
		userPaused = !userPaused;
		syncToggle();
		if (userPaused) {
			stop();
		} else {
			start();
		}
	});

	slider.addEventListener('pointerenter', () => {
		hoverPaused = true;
		stop();
	});
	slider.addEventListener('pointerleave', () => {
		hoverPaused = false;
		start();
	});
	slider.addEventListener('focusin', () => {
		hoverPaused = true;
		stop();
	});
	slider.addEventListener('focusout', () => {
		hoverPaused = false;
		start();
	});

	document.addEventListener('visibilitychange', () => {
		if (document.hidden) {
			stop();
		} else {
			start();
		}
	});

	// Horizontal swipe: a short horizontal drag changes slides, a mostly
	// vertical drag is left alone so the page can still scroll.
	let touchStartX = 0;
	let touchStartY = 0;
	slider.addEventListener(
		'touchstart',
		(event) => {
			const touch = event.changedTouches[0];
			touchStartX = touch.clientX;
			touchStartY = touch.clientY;
		},
		{ passive: true },
	);
	slider.addEventListener(
		'touchend',
		(event) => {
			const touch = event.changedTouches[0];
			const deltaX = touch.clientX - touchStartX;
			const deltaY = touch.clientY - touchStartY;
			if (Math.abs(deltaX) < 40 || Math.abs(deltaX) < Math.abs(deltaY)) {
				return;
			}
			show(current + (deltaX < 0 ? 1 : -1));
			start();
		},
		{ passive: true },
	);

	if (!reducedMotion) {
		start();
	}
}

function initGoldMarkets(): void {
	if (getKkData().isMarketsEnabled === false) {
		return;
	}
	$all<HTMLElement>('[data-kk-gold-slider]').forEach((slider) => initGoldSlider(slider));
	$all<HTMLElement>('[data-kk-markets-slider]').forEach((slider) => initMarketsSlider(slider));
	initLivePrices();
	initMarketHeaderMarquee();
}

// Live prices (client-side refresh).
//
// The server renders the price (from the backend provider, cached in a
// transient), so the widget shows a value immediately. This refreshes it
// in the browser without a page reload:
//  - localStorage first: a cached price (TTL 15 minutes) is used and
//    the server is not called at all.
//  - When the cache is stale, the browser calls the public API
//    directly for providers that support it (Xaus, CoinGecko and the
//    scraped prices.json - all CORS-enabled and keyless). Providers
//    without a direct client API (Indodax, Yahoo Finance, Manual)
//    fall back to the backend endpoint `kk_markets_prices`.
//  - A failed fetch leaves the server-rendered price in place; the
//    widget never goes blank because the live call failed.

interface LivePrice {
	price: number;
	change: number | null;
	updated: number;
}

type LivePriceMap = Record<string, LivePrice>;

const LIVE_CACHE_TTL_MS = 15 * 60 * 1000;
const MAX_CACHE_TTL_MINUTES = 24 * 60;

function liveCacheKey(market: string): string {
	return `kk-market-${market}`;
}

/**
 * A container's cache lifetime in milliseconds, from its
 * data-kk-cache-ttl-minutes attribute (the header strip sets 30,
 * the widgets leave it unset and keep the 15-minute default).
 * Out-of-range or unreadable values fall back to the default.
 */
function containerTtlMs(container: HTMLElement): number {
	const parsed = Number(container.dataset.kkCacheTtlMinutes ?? '');
	if (!Number.isFinite(parsed) || parsed <= 0) {
		return LIVE_CACHE_TTL_MS;
	}
	return Math.min(parsed, MAX_CACHE_TTL_MINUTES) * 60 * 1000;
}

function readLiveCache(market: string, ttlMs: number = LIVE_CACHE_TTL_MS): LivePriceMap | null {
	try {
		const raw = window.localStorage.getItem(liveCacheKey(market));
		if (!raw) {
			return null;
		}
		const parsed = JSON.parse(raw) as { savedAt?: number; prices?: LivePriceMap };
		if (!parsed || typeof parsed.savedAt !== 'number' || !parsed.prices) {
			return null;
		}
		if (Date.now() - parsed.savedAt > ttlMs) {
			return null;
		}
		return parsed.prices;
	} catch {
		return null;
	}
}

function writeLiveCache(market: string, prices: LivePriceMap): void {
	try {
		window.localStorage.setItem(
			liveCacheKey(market),
			JSON.stringify({ savedAt: Date.now(), prices }),
		);
	} catch {
		// Storage full or private mode - the next load just fetches again.
	}
}

function formatIdr(price: number): string {
	return `Rp${Math.round(price)
		.toString()
		.replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
}

/**
 * The server's Markets::formatPrice() for the browser: rupiah is
 * rounded with the Rp prefix, anything else keeps two decimals
 * with the currency code (Brent is "84.25 USD", never "Rp84").
 */
function formatPrice(price: number, currency: string): string {
	if (currency === 'IDR') {
		return formatIdr(price);
	}
	return `${price.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`;
}

function formatChange(change: number): string {
	const sign = change > 0 ? '+' : '';
	return `${sign}${change.toFixed(2)}%`;
}

function updatePriceDom(container: HTMLElement, prices: LivePriceMap): void {
	$all<HTMLElement>('[data-kk-symbol]', container).forEach((row) => {
		const symbol = row.dataset.kkSymbol ?? '';
		const market = row.dataset.kkMarket ?? '';
		// A row with a market (the header strip, which mixes
		// markets) looks up market|symbol; a single-market row
		// (the widgets) looks up the plain symbol as before.
		const entry = market !== '' ? (prices[`${market}|${symbol}`] ?? prices[symbol]) : prices[symbol];
		if (!entry || !Number.isFinite(entry.price) || entry.price <= 0) {
			return;
		}
		const priceEl = row.querySelector<HTMLElement>('[data-kk-price]');
		if (priceEl) {
			priceEl.textContent = formatPrice(entry.price, row.dataset.kkCurrency ?? 'IDR');
		}
		const changeEl = row.querySelector<HTMLElement>('[data-kk-change]');
		if (!changeEl) {
			return;
		}
		// The header strip's change chip carries an icon svg: the
		// text lives in its [data-kk-change-text] span, so updating
		// the chip's textContent would wipe the icon. Widget chips
		// have no such span and update as before.
		const textEl = changeEl.querySelector<HTMLElement>('[data-kk-change-text]') ?? changeEl;
		if (entry.change === null || !Number.isFinite(entry.change)) {
			textEl.textContent = '';
			changeEl.classList.remove('kk-market-change-up', 'kk-market-change-down');
			return;
		}
		const wasUp = changeEl.classList.contains('kk-market-change-up');
		const isUp = entry.change >= 0;
		textEl.textContent = formatChange(entry.change);
		changeEl.classList.toggle('kk-market-change-up', isUp);
		changeEl.classList.toggle('kk-market-change-down', !isUp);
		// The icon follows the sign: when the day change flips,
		// swap the svg for the server-rendered one of the new
		// sign (data-kk-icon-up / data-kk-icon-down).
		if (wasUp !== isUp) {
			const svg = changeEl.querySelector('svg');
			const icon = isUp ? changeEl.dataset.kkIconUp : changeEl.dataset.kkIconDown;
			if (svg && icon) {
				const tpl = document.createElement('template');
				tpl.innerHTML = icon.trim();
				const node = tpl.content.firstElementChild;
				if (node) {
					svg.replaceWith(node);
				}
			}
		}
	});
}

/** Parse a provider's public API response into the symbol map. */
function parseClientApi(provider: string, data: unknown): LivePriceMap | null {
	if (typeof data !== 'object' || data === null) {
		return null;
	}
	const now = Math.floor(Date.now() / 1000);
	const out: LivePriceMap = {};

	if (provider === 'xaus') {
		const price = (data as { xau?: { price?: unknown } }).xau?.price;
		if (typeof price === 'number' && price > 0) {
			out.spot = { price, change: null, updated: now };
		}
	} else if (provider === 'coingecko') {
		const ids: Record<string, string> = {
			btc: 'bitcoin',
			eth: 'ethereum',
			sol: 'solana',
			usdt: 'tether',
			xrp: 'ripple',
		};
		const body = data as Record<string, { idr?: unknown; idr_24h_change?: unknown }>;
		for (const [symbol, coinId] of Object.entries(ids)) {
			const row = body[coinId];
			const price = row?.idr;
			if (typeof price !== 'number' || price <= 0) {
				continue;
			}
			const change = row.idr_24h_change;
			out[symbol] = {
				price,
				change: typeof change === 'number' ? change : null,
				updated: now,
			};
		}
	} else if (provider === 'scraped-gold') {
		const rows = (data as { prices?: Record<string, { price?: unknown; updated?: unknown }> }).prices;
		if (typeof rows !== 'object' || rows === null) {
			return null;
		}
		// The scraper publishes every symbol it covers; the strip only
		// renders the enabled ones, so any symbol present here is used.
		for (const symbol of ['spot', 'antam', 'ubs', 'galeri-24', 'lotus-archi']) {
			const row = rows[symbol];
			const price = row?.price;
			if (typeof price !== 'number' || price <= 0) {
				continue;
			}
			const updated = row.updated ? Date.parse(String(row.updated)) / 1000 : now;
			out[symbol] = {
				price,
				change: null,
				updated: Number.isFinite(updated) ? updated : now,
			};
		}
	}

	return Object.keys(out).length > 0 ? out : null;
}

async function fetchLivePrices(container: HTMLElement): Promise<LivePriceMap | null> {
	const market = container.dataset.kkMarket ?? '';
	const provider = container.dataset.kkProvider ?? '';
	const clientApi = container.dataset.kkClientApi ?? '';

	if (!market || provider === 'manual') {
		return null;
	}

	// Direct client call when the provider supports it.
	if (clientApi !== '') {
		try {
			const response = await fetch(clientApi, { headers: { Accept: 'application/json' } });
			if (response.ok) {
				const parsed = parseClientApi(provider, await response.json());
				if (parsed !== null) {
					return parsed;
				}
			}
		} catch {
			// Fall through to the backend endpoint.
		}
	}

	// Backend endpoint (Indodax, Yahoo, or a failed direct call).
	const kkData = getKkData();
	if (!kkData.ajaxUrl || !kkData.nonce) {
		return null;
	}
	try {
		const data = await postAjax<{ prices?: Record<string, { price?: number; change?: number | null; updated?: number }> }>(
			{ ajaxUrl: kkData.ajaxUrl, nonce: kkData.nonce },
			'kk_markets_prices',
			{ market },
		);
		const rows = data.prices ?? {};
		const out: LivePriceMap = {};
		for (const [symbol, row] of Object.entries(rows)) {
			if (typeof row.price !== 'number' || row.price <= 0) {
				continue;
			}
			out[symbol] = {
				price: row.price,
				change: typeof row.change === 'number' ? row.change : null,
				updated: typeof row.updated === 'number' ? row.updated : Math.floor(Date.now() / 1000),
			};
		}
		return Object.keys(out).length > 0 ? out : null;
	} catch {
		return null;
	}
}

function initLivePrices(): void {
	$all<HTMLElement>('[data-kk-market-prices]').forEach((container) => {
		const ttlMs = containerTtlMs(container);

		// The header strip: one container for every market, fed by
		// the rewrite endpoint instead of the per-market calls.
		const stripEndpoint = container.dataset.kkStripEndpoint ?? '';
		if (stripEndpoint !== '') {
			initStripPrices(container, stripEndpoint, ttlMs);
			return;
		}

		const market = container.dataset.kkMarket ?? '';
		if (!market) {
			return;
		}

		// localStorage first: a fresh cache means no server call at all.
		const cached = readLiveCache(market, ttlMs);
		if (cached !== null) {
			updatePriceDom(container, cached);
			return;
		}

		void fetchLivePrices(container).then((prices) => {
			if (prices === null) {
				return;
			}
			writeLiveCache(market, prices);
			updatePriceDom(container, prices);
		});
	});
}

// Header strip refresh.
//
// The strip mixes markets in one flat row, so it cannot use the
// per-market containers above: it fetches the whole strip at once
// from the rewrite endpoint (/kk-market-prices/) and caches it
// under its own key with the strip's 30-minute lifetime. The
// endpoint also returns the pre-formatted snapshot caption, so the
// caption refreshes with the prices instead of going stale under
// a full-page cache. A failed fetch leaves the server-rendered
// strip in place; the strip never goes blank because the live
// call failed.

const STRIP_CACHE_KEY = 'kk-market-strip';

interface StripCache {
	savedAt: number;
	markets: Record<string, LivePriceMap>;
	caption: string;
}

interface StripPrices {
	markets: Record<string, LivePriceMap>;
	caption: string;
}

function readStripCache(ttlMs: number): StripCache | null {
	try {
		const raw = window.localStorage.getItem(STRIP_CACHE_KEY);
		if (!raw) {
			return null;
		}
		const parsed = JSON.parse(raw) as StripCache;
		if (
			!parsed
			|| typeof parsed.savedAt !== 'number'
			|| typeof parsed.markets !== 'object'
			|| parsed.markets === null
			|| typeof parsed.caption !== 'string'
		) {
			return null;
		}
		if (Date.now() - parsed.savedAt > ttlMs) {
			return null;
		}
		return parsed;
	} catch {
		return null;
	}
}

function writeStripCache(markets: Record<string, LivePriceMap>, caption: string): void {
	try {
		window.localStorage.setItem(
			STRIP_CACHE_KEY,
			JSON.stringify({ savedAt: Date.now(), markets, caption }),
		);
	} catch {
		// Storage full or private mode - the next load just fetches again.
	}
}

async function fetchStripPrices(endpoint: string): Promise<StripPrices | null> {
	try {
		const response = await fetch(endpoint, { headers: { Accept: 'application/json' } });
		if (!response.ok) {
			return null;
		}
		const data = (await response.json()) as {
			markets?: Record<string, Record<string, { price?: unknown; change?: unknown; updated?: unknown }>>;
			caption?: unknown;
		};
		if (typeof data !== 'object' || data === null || typeof data.markets !== 'object' || data.markets === null) {
			return null;
		}
		const markets: Record<string, LivePriceMap> = {};
		for (const [market, rows] of Object.entries(data.markets)) {
			if (typeof rows !== 'object' || rows === null) {
				continue;
			}
			const out: LivePriceMap = {};
			for (const [symbol, row] of Object.entries(rows)) {
				if (typeof row !== 'object' || row === null || typeof row.price !== 'number' || row.price <= 0) {
					continue;
				}
				out[symbol] = {
					price: row.price,
					change: typeof row.change === 'number' ? row.change : null,
					updated: typeof row.updated === 'number' ? row.updated : Math.floor(Date.now() / 1000),
				};
			}
			if (Object.keys(out).length > 0) {
				markets[market] = out;
			}
		}
		if (Object.keys(markets).length === 0) {
			return null;
		}
		return { markets, caption: typeof data.caption === 'string' ? data.caption : '' };
	} catch {
		return null;
	}
}

function updateStripCaption(container: HTMLElement, caption: string): void {
	const captionEl = container.querySelector<HTMLElement>('.kk-market-header-snapshot');
	if (!captionEl) {
		return;
	}
	captionEl.textContent = caption;
	captionEl.style.display = caption === '' ? 'none' : '';
}

function applyStripData(container: HTMLElement, markets: Record<string, LivePriceMap>, caption: string): void {
	const flat: LivePriceMap = {};
	for (const [market, prices] of Object.entries(markets)) {
		for (const [symbol, entry] of Object.entries(prices)) {
			flat[`${market}|${symbol}`] = entry;
		}
	}
	updatePriceDom(container, flat);
	updateStripCaption(container, caption);
}

function initStripPrices(container: HTMLElement, endpoint: string, ttlMs: number): void {
	const cached = readStripCache(ttlMs);
	if (cached !== null) {
		applyStripData(container, cached.markets, cached.caption);
		return;
	}

	void fetchStripPrices(endpoint).then((data) => {
		if (data === null) {
			return;
		}
		writeStripCache(data.markets, data.caption);
		// The strip fetch covers every market, so the widgets on
		// this page find a fresh per-market cache on their next
		// load instead of fetching again.
		for (const [market, prices] of Object.entries(data.markets)) {
			writeLiveCache(market, prices);
		}
		applyStripData(container, data.markets, data.caption);
	});
}

/* Market header index: desktop marquee.
 *
 * On desktop the picked items are wider than the strip's box, so the
 * overflowing items sit cut off past the right edge. When the row
 * overflows, the strip scrolls on its own like a marquee: the items
 * are wrapped in a track with two identical sets and the track
 * translates -50% on a linear loop (the same two-set pattern as the
 * markets marquee), so the seam never shows. The loop passes one
 * item per cycle interval (the strip's data-speed, 7s by default),
 * so the pace follows the cycle interval Customizer setting - no
 * new setting.
 *
 * The marquee only exists on desktop (min-width: 768px, the mirror
 * of the mobile swipeable row's max-width: 767px) and never under
 * reduced motion; below the breakpoint the markup is restored to
 * exactly what the server rendered, so the mobile swipeable row is
 * untouched. The clones carry the same data-kk-symbol attributes, so updatePriceDom() refreshes them in
 * place with the originals. Hover/focus pauses the loop (CSS); the
 * animation itself is CSS, so a hidden tab pauses it too.
 */
function initMarketHeaderMarquee(): void {
	const strip = document.getElementById('kk-market-header-strip');
	if (!strip) {
		return;
	}
	const desktop = window.matchMedia('(min-width: 768px)');
	const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
	let built = false;
	let resizeTimer: number | null = null;

	const speedSeconds = (): number => {
		const raw = Number(strip.getAttribute('data-speed') ?? '');
		if (!Number.isFinite(raw) || raw <= 0) {
			return 7;
		}
		return Math.min(120, Math.max(2, raw));
	}

	const originals = (): HTMLElement[] => {
		return $all<HTMLElement>('.kk-index-item:not(.kk-index-item--clone)', strip);
	}

	const overflows = (): boolean => {
		const set = strip.querySelector<HTMLElement>('.kk-index-strip-set:not(.kk-index-strip-set--clone)');
		const measured = set ?? strip;
		return measured.scrollWidth > strip.clientWidth;
	}

	const build = (): void => {
		if (built) {
			return;
		}
		const items = originals();
		if (items.length <= 1 || !overflows()) {
			return;
		}
		const track = document.createElement('div');
		track.className = 'kk-index-strip-track';
		const set = document.createElement('div');
		set.className = 'kk-index-strip-set';
		const cloneSet = document.createElement('div');
		cloneSet.className = 'kk-index-strip-set kk-index-strip-set--clone';
		cloneSet.setAttribute('aria-hidden', 'true');
		for (const item of items) {
			set.appendChild(item);
		}
		for (const item of items) {
			const clone = item.cloneNode(true) as HTMLElement;
			clone.classList.add('kk-index-item--clone');
			clone.classList.remove('is-active', 'is-leaving');
			clone.setAttribute('aria-hidden', 'true');
			cloneSet.appendChild(clone);
		}
		track.append(set, cloneSet);
		strip.appendChild(track);
		strip.classList.add('kk-index-strip--marquee');
		strip.style.setProperty('--kk-index-strip-duration', `${items.length * speedSeconds()}s`);
		built = true;
	}

	const teardown = (): void => {
		if (!built) {
			return;
		}
		const track = strip.querySelector<HTMLElement>('.kk-index-strip-track');
		if (track) {
			const set = track.querySelector<HTMLElement>('.kk-index-strip-set:not(.kk-index-strip-set--clone)');
			if (set) {
				for (const item of $all<HTMLElement>('.kk-index-item:not(.kk-index-item--clone)', set)) {
					strip.appendChild(item);
				}
			}
			track.remove();
		}
		strip.classList.remove('kk-index-strip--marquee');
		strip.style.removeProperty('--kk-index-strip-duration');
		built = false;
	}

	const sync = (): void => {
		if (desktop.matches && !reduced.matches && overflows()) {
			build();
		} else {
			teardown();
		}
	}

	desktop.addEventListener('change', sync);
	reduced.addEventListener('change', sync);
	window.addEventListener('resize', () => {
		if (resizeTimer !== null) {
			window.clearTimeout(resizeTimer);
		}
		resizeTimer = window.setTimeout(sync, 150);
	});
	if (typeof document.fonts !== 'undefined') {
		void document.fonts.ready.then(sync);
	}
	sync();
}

document.addEventListener('DOMContentLoaded', initGoldMarkets);
