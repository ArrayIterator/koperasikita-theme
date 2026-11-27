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
				changeEl.classList.remove('kk-market-change-up', 'kk-market-change-down');
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
