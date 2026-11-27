/**
 * Koperasi Kita - gold prices slider and markets strip.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when the Markets feature is enabled (window.kkData.isMarketsEnabled);
 * a disabled feature loads neither this script nor any markets markup,
 * so there is nothing to guard against beyond the flag.
 *
 * The gold slider auto-advances on a timer, pauses on hover/focus, when
 * the tab is hidden, and when the user presses pause. Prev/next wrap
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
 * Auto-advancing vertical slider, shared by the gold slider and the
 * markets vertical slider. `kind` is the data-attribute prefix
 * ('gold' or 'markets'): slides are [data-kk-<kind>-slide], dots
 * [data-kk-<kind>-dot], controls [data-kk-<kind>-prev/next/toggle].
 * `label` is the human name used in the play/pause toggle aria-label
 * ('gold prices' / 'markets').
 */
function initAutoSlider(slider: HTMLElement, kind: 'gold' | 'markets', label: string): void {
	const slides = $all<HTMLElement>(`[data-kk-${kind}-slide]`, slider);
	if (slides.length <= 1) {
		return;
	}

	const dots = $all<HTMLButtonElement>(`[data-kk-${kind}-dot]`, slider);
	const prevButton = slider.querySelector<HTMLButtonElement>(`[data-kk-${kind}-prev]`);
	const nextButton = slider.querySelector<HTMLButtonElement>(`[data-kk-${kind}-next]`);
	const toggleButton = slider.querySelector<HTMLButtonElement>(`[data-kk-${kind}-toggle]`);
	const pauseIcon = toggleButton?.querySelector('.kk-widget-toggle-pause') as HTMLElement | null;
	const playIcon = toggleButton?.querySelector('.kk-widget-toggle-play') as HTMLElement | null;

	const parsedInterval = Number(slider.dataset.interval ?? 5000);
	const interval = Number.isFinite(parsedInterval) && parsedInterval >= 2000 ? parsedInterval : 5000;

	// Horizontal styles keep every slide visible in a swipeable row
	// and scroll the active one into view; the vertical slider
	// toggles a single active slide. The markets slider has no style
	// attribute, so it stays vertical. data-autoplay="0" disables
	// the timer.
	const style = slider.dataset.style ?? 'vertical-carousel';
	const horizontal = style === 'horizontal-carousel' || style === 'slider-horizontal';
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
		if (horizontal) {
			slides[current].scrollIntoView({
				behavior: reducedMotion ? 'auto' : 'smooth',
				inline: 'start',
				block: 'nearest',
			});
		}
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
		toggleButton.setAttribute('aria-label', userPaused ? `Play ${label}` : `Pause ${label}`);
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

	if (horizontal) {
		// The row scrolls natively on swipe; sync the active card
		// and dots to where the track stopped.
		const track = slides[0].parentElement;
		if (track !== null) {
			let scrollRaf = 0;
			track.addEventListener(
				'scroll',
				() => {
					if (scrollRaf !== 0) {
						return;
					}
					scrollRaf = window.requestAnimationFrame(() => {
						scrollRaf = 0;
						const trackLeft = track.getBoundingClientRect().left;
						let best = current;
						let bestDist = Number.POSITIVE_INFINITY;
						slides.forEach((slide, slideIndex) => {
							const dist = Math.abs(slide.getBoundingClientRect().left - trackLeft);
							if (dist < bestDist) {
								bestDist = dist;
								best = slideIndex;
							}
						});
						if (best !== current) {
							slides[current].classList.remove('is-active');
							current = best;
							slides[current].classList.add('is-active');
							syncDots();
						}
					});
				},
				{ passive: true },
			);
		}
	} else {
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
	}

	if (!reducedMotion) {
		start();
	}
}

function initGoldMarkets(): void {
	if (getKkData().isMarketsEnabled === false) {
		return;
	}
	$all<HTMLElement>('[data-kk-gold-slider]').forEach((slider) => initAutoSlider(slider, 'gold', 'gold prices'));
	$all<HTMLElement>('[data-kk-markets-slider]').forEach((slider) => initAutoSlider(slider, 'markets', 'markets'));
	initLivePrices();
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

function liveCacheKey(market: string): string {
	return `kk-market-${market}`;
}

function readLiveCache(market: string): LivePriceMap | null {
	try {
		const raw = window.localStorage.getItem(liveCacheKey(market));
		if (!raw) {
			return null;
		}
		const parsed = JSON.parse(raw) as { savedAt?: number; prices?: LivePriceMap };
		if (!parsed || typeof parsed.savedAt !== 'number' || !parsed.prices) {
			return null;
		}
		if (Date.now() - parsed.savedAt > LIVE_CACHE_TTL_MS) {
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

function formatChange(change: number): string {
	const sign = change > 0 ? '+' : '';
	return `${sign}${change.toFixed(2)}%`;
}

function updatePriceDom(container: HTMLElement, prices: LivePriceMap): void {
	$all<HTMLElement>('[data-kk-symbol]', container).forEach((row) => {
		const symbol = row.dataset.kkSymbol ?? '';
		const entry = prices[symbol];
		if (!entry || !Number.isFinite(entry.price) || entry.price <= 0) {
			return;
		}
		const priceEl = row.querySelector<HTMLElement>('[data-kk-price]');
		if (priceEl) {
			priceEl.textContent = formatIdr(entry.price);
		}
		const changeEl = row.querySelector<HTMLElement>('[data-kk-change]');
		if (changeEl) {
			if (entry.change === null || !Number.isFinite(entry.change)) {
				changeEl.textContent = '';
			} else {
				changeEl.textContent = formatChange(entry.change);
				changeEl.classList.toggle('kk-market-change-up', entry.change >= 0);
				changeEl.classList.toggle('kk-market-change-down', entry.change < 0);
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
		const market = container.dataset.kkMarket ?? '';
		if (!market) {
			return;
		}

		// localStorage first: a fresh cache means no server call at all.
		const cached = readLiveCache(market);
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

document.addEventListener('DOMContentLoaded', initGoldMarkets);
