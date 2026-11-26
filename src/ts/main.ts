/**
 * Koperasi Kita — main frontend script.
 *
 * Strict TypeScript, no jQuery, no external libraries. Every feature is
 * guarded by the kkData flags localised by the theme, so a disabled
 * feature runs zero code.
 */

export {};

interface KkData {
	isBookmarksEnabled?: boolean;
	isPwaEnabled?: boolean;
	pwaPromptDelayMs?: number;
	isWeatherEnabled?: boolean;
	weatherCacheLifetimeMin?: number;
	isMarketsEnabled?: boolean;
	bookmarksPageUrl?: string;
	ajaxUrl?: string;
	nonce?: string;
	themeUrl?: string;
}

declare global {
	interface Window {
		kkData?: KkData;
	}
}

type ColorScheme = 'system' | 'light' | 'dark';

const SCHEME_KEY = 'kk-color-scheme';
const LAYOUT_KEY = 'kk-layout';
const DB_NAME = 'koperasikita';
const DB_VERSION = 1;
const STORE_NAME = 'kk-bookmarks';
const INSTALL_SEEN_KEY = 'kk-install-prompt-seen';

interface BookmarkEntry {
	postId: number;
	title: string;
	url: string;
	excerpt: string;
	thumbnail: string;
	note: string;
	createdAt: number;
}

function getKkData(): KkData {
	return window.kkData ?? {};
}

function $(selector: string, root: ParentNode = document): HTMLElement | null {
	return root.querySelector(selector);
}

function $all<T extends Element>(selector: string, root: ParentNode = document): T[] {
	return Array.from(root.querySelectorAll<T>(selector));
}

/* ------------------------------------------------------------------ */
/* Colour scheme                                                       */
/* ------------------------------------------------------------------ */

// The inline script in header.php already resolved the stored
// preference (or the OS scheme) before first paint, so the page never
// flashes. This module keeps the same preference in sync afterwards
// and follows OS changes while the preference is "system".
const SCHEME_MEDIA = window.matchMedia('(prefers-color-scheme: dark)');

function getStoredScheme(): ColorScheme {
	const stored = window.localStorage.getItem(SCHEME_KEY);
	if (stored === 'light' || stored === 'dark' || stored === 'system') {
		return stored;
	}
	// No stored choice: the Customizer default scheme (set on <html>
	// as data-kk-default-scheme by header.php) wins over "system".
	const fallback = document.documentElement.getAttribute('data-kk-default-scheme');
	if (fallback === 'light' || fallback === 'dark' || fallback === 'system') {
		return fallback;
	}
	return 'system';
}

function resolveScheme(scheme: ColorScheme): 'light' | 'dark' {
	if (scheme === 'light' || scheme === 'dark') {
		return scheme;
	}
	return SCHEME_MEDIA.matches ? 'dark' : 'light';
}

function onSystemSchemeChange(event: MediaQueryListEvent): void {
	document.documentElement.setAttribute('data-color-scheme', event.matches ? 'dark' : 'light');
}

function applyScheme(scheme: ColorScheme): void {
	// The attribute always carries the RESOLVED scheme (light/dark),
	// never the literal "system" — that is what the header script set
	// before paint, and what the CSS reads.
	document.documentElement.setAttribute('data-color-scheme', resolveScheme(scheme));
	// The stored preference itself, for the toggle icon (a monitor
	// while it is "system"); the header script sets this too, before
	// first paint, so the icon never flashes the wrong glyph.
	document.documentElement.setAttribute('data-kk-scheme', scheme);
	// Follow the OS while the preference is "system"; stop when the
	// user pins light or dark.
	if (scheme === 'system') {
		SCHEME_MEDIA.addEventListener('change', onSystemSchemeChange);
	} else {
		SCHEME_MEDIA.removeEventListener('change', onSystemSchemeChange);
	}
	// Every toggle (header on desktop, drawer footer on mobile) stays
	// in sync. aria-pressed reflects the resolved scheme (is the page
	// dark right now); the label names the next mode in the cycle,
	// which depends on the stored preference.
	const isDark = resolveScheme(scheme) === 'dark';
	$all<HTMLButtonElement>('[data-kk-scheme-toggle]').forEach((toggle) => {
		toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
		toggle.setAttribute(
			'aria-label',
			scheme === 'system'
				? 'Switch to light mode'
				: scheme === 'light'
					? 'Switch to dark mode'
					: 'Switch to system mode',
		);
	});
}

function initColorScheme(): void {
	applyScheme(getStoredScheme());
	$all<HTMLButtonElement>('[data-kk-scheme-toggle]').forEach((toggle) => {
		toggle.addEventListener('click', () => {
			const current = getStoredScheme();
			const next: ColorScheme = current === 'system' ? 'light' : current === 'light' ? 'dark' : 'system';
			window.localStorage.setItem(SCHEME_KEY, next);
			applyScheme(next);
		});
	});
}

/* ------------------------------------------------------------------ */
/* Mobile nav                                                          */
/* ------------------------------------------------------------------ */

function initMobileNav(): void {
	const toggle = $('#kk-menu-toggle') as HTMLButtonElement | null;
	const nav = $('#kk-primary-nav');
	const backdrop = $('#kk-nav-backdrop');
	const closeButton = $('#kk-drawer-close') as HTMLButtonElement | null;
	const backBar = $('#kk-drawer-back');
	const backButton = $('#kk-drawer-back-button') as HTMLButtonElement | null;
	const backLabel = $('#kk-drawer-back-label');
	if (!toggle || !nav) {
		return;
	}

	const desktopQuery = window.matchMedia('(min-width: 1024px)');
	const isDesktop = (): boolean => desktopQuery.matches;
	let drillToggle: HTMLButtonElement | null = null;

	// Multi-level sub-menu drill-down: every parent item at ANY depth
	// gets a chevron button after its link. Tapping it pushes that
	// item onto the drill stack and shows its sub-menu as the current
	// level under the back bar; the back button pops one level, and
	// the back label is the current level's parent. The stack is the
	// drill path from the top level down, so a 10-level test menu
	// drills all the way (the old code only drilled one level: it
	// chevroned top-level items only and reset on the first back).
	const drillStack: HTMLLIElement[] = [];

	const syncDrill = (): void => {
		// Re-mark the stack: every item but the deepest is an
		// ancestor, the deepest is the current level.
		$all('.is-drill-ancestor, .is-drill-current', nav).forEach((item) => {
			item.classList.remove('is-drill-ancestor', 'is-drill-current');
		});
		drillStack.forEach((item, index) => {
			item.classList.add(index === drillStack.length - 1 ? 'is-drill-current' : 'is-drill-ancestor');
		});
		if (drillStack.length > 0) {
			nav.classList.add('is-drilling');
			if (backBar) {
				backBar.hidden = false;
			}
			const current = drillStack[drillStack.length - 1];
			const currentLink = current.querySelector<HTMLAnchorElement>(':scope > a');
			if (backLabel) {
				backLabel.textContent = currentLink?.textContent?.trim() ?? '';
			}
		} else {
			nav.classList.remove('is-drilling');
			if (backBar) {
				backBar.hidden = true;
			}
			if (backLabel) {
				backLabel.textContent = '';
			}
		}
	};

	const chevronSvg =
		'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
	$all<HTMLLIElement>('.kk-primary-menu li.menu-item-has-children', nav).forEach((item) => {
		const link = item.querySelector<HTMLAnchorElement>(':scope > a');
		const sub = item.querySelector<HTMLElement>(':scope > .sub-menu');
		if (!link || !sub) {
			return;
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'kk-submenu-toggle';
		button.setAttribute('aria-expanded', 'false');
		button.setAttribute('aria-label', `Open ${link.textContent?.trim() ?? ''} submenu`);
		button.innerHTML = chevronSvg;
		link.after(button);
		button.addEventListener('click', () => {
			// Re-tapping an item already on the stack truncates the
			// stack to it instead of pushing a duplicate; normally a
			// tap is on a current-level item, so this just pushes.
			const existing = drillStack.indexOf(item);
			if (existing >= 0) {
				drillStack.length = existing + 1;
			} else {
				drillStack.push(item);
			}
			syncDrill();
			button.setAttribute('aria-expanded', 'true');
			drillToggle = button;
			backButton?.focus();
		});
	});

	const resetDrill = (): void => {
		drillStack.length = 0;
		syncDrill();
		$all<HTMLButtonElement>('.kk-submenu-toggle', nav).forEach((button) => {
			button.setAttribute('aria-expanded', 'false');
		});
		drillToggle = null;
	};

	const openNav = (): void => {
		if (isDesktop()) {
			return;
		}
		nav.classList.add('is-open');
		nav.inert = false;
		toggle.setAttribute('aria-expanded', 'true');
		toggle.setAttribute('aria-label', 'Close menu');
		if (backdrop) {
			backdrop.hidden = false;
			// Force a frame so the opacity transition runs.
			window.requestAnimationFrame(() => backdrop.classList.add('is-visible'));
		}
		document.documentElement.classList.add('kk-nav-open');
		closeButton?.focus();
	};

	const closeNav = (refocusToggle: boolean): void => {
		nav.classList.remove('is-open');
		nav.inert = true;
		toggle.setAttribute('aria-expanded', 'false');
		toggle.setAttribute('aria-label', 'Open menu');
		if (backdrop) {
			backdrop.classList.remove('is-visible');
			window.setTimeout(() => {
				if (!nav.classList.contains('is-open')) {
					backdrop.hidden = true;
				}
			}, 300);
		}
		document.documentElement.classList.remove('kk-nav-open');
		resetDrill();
		if (refocusToggle) {
			toggle.focus();
		}
	};

	// Closed on load: keep the drawer out of the tab order on mobile.
	// On desktop the nav is the horizontal bar and must stay reachable.
	nav.inert = !isDesktop();

	toggle.addEventListener('click', () => {
		if (nav.classList.contains('is-open')) {
			closeNav(false);
		} else {
			openNav();
		}
	});
	closeButton?.addEventListener('click', () => closeNav(true));
	backdrop?.addEventListener('click', () => closeNav(true));
	backButton?.addEventListener('click', () => {
		// Slide the parent level back in from the left (AMP-style);
		// the class comes off once the 0.28s animation has run.
		// Back pops ONE level (not a full reset), so a deep menu
		// walks back up level by level.
		nav.classList.add('is-sliding-back');
		window.setTimeout(() => nav.classList.remove('is-sliding-back'), 300);
		const popped = drillStack.pop();
		if (popped) {
			const toggle = popped.querySelector<HTMLButtonElement>(':scope > .kk-submenu-toggle');
			toggle?.setAttribute('aria-expanded', 'false');
			// The popped item is visible again as a current-level
			// item, so focus returns to its chevron.
			drillToggle = toggle;
		}
		syncDrill();
		drillToggle?.focus();
	});
	// A menu link navigates away; close the drawer first so a
	// same-page jump does not leave it open over the content.
	$all<HTMLAnchorElement>('.kk-primary-menu a', nav).forEach((link) => {
		link.addEventListener('click', () => closeNav(false));
	});
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && nav.classList.contains('is-open')) {
			closeNav(true);
		}
	});
	// Resizing to desktop turns the drawer back into the bar: drop
	// the drawer state instead of leaving it open off-canvas.
	desktopQuery.addEventListener('change', (event) => {
		if (event.matches) {
			nav.classList.remove('is-open');
			nav.inert = false;
			if (backdrop) {
				backdrop.classList.remove('is-visible');
				backdrop.hidden = true;
			}
			document.documentElement.classList.remove('kk-nav-open');
			resetDrill();
			toggle.setAttribute('aria-expanded', 'false');
			toggle.setAttribute('aria-label', 'Open menu');
		} else {
			nav.inert = !nav.classList.contains('is-open');
		}
	});
}

/* ------------------------------------------------------------------ */
/* Modals (centred popup + blurred backdrop)                           */
/* ------------------------------------------------------------------ */

let lastFocusedElement: HTMLElement | null = null;

function openModal(modal: HTMLElement): void {
	lastFocusedElement = document.activeElement as HTMLElement | null;
	modal.hidden = false;
	// Lock the page behind the modal on both scroll roots: locking body
	// alone does not stop scrolling on every mobile browser.
	document.body.style.overflow = 'hidden';
	document.documentElement.style.overflow = 'hidden';
	const focusable = modal.querySelector<HTMLElement>(
		'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])',
	);
	if (focusable) {
		focusable.focus();
	}
}

function closeModal(modal: HTMLElement): void {
	modal.hidden = true;
	document.body.style.overflow = '';
	document.documentElement.style.overflow = '';
	if (lastFocusedElement) {
		lastFocusedElement.focus();
		lastFocusedElement = null;
	}
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

function initModals(): void {
	$all<HTMLElement>('.kk-modal').forEach((modal) => {
		$all('[data-kk-modal-close]', modal).forEach((closer) => {
			closer.addEventListener('click', () => closeModal(modal));
		});
	});
	document.addEventListener('keydown', (event) => {
		if (event.key !== 'Escape') {
			return;
		}
		$all<HTMLElement>('.kk-modal').forEach((modal) => {
			if (modal.hidden) {
				return;
			}
			if (isEditableElement(event.target) && modal.contains(event.target as Node)) {
				// The field owns Escape: leave it and keep the modal
				// open. A second Escape (focus outside any field) closes.
				(event.target as HTMLElement).blur();
				return;
			}
			closeModal(modal);
		});
	});
}

/* Expanding search: the icon toggles a field that grows out of it
 * to the left. Escape, an outside click or submit closes it again;
 * the query is cleared on close so a stale query never reopens. */
function initExpandingSearch(): void {
	const form = $('#kk-search-expand') as HTMLFormElement | null;
	const toggle = $('#kk-search-open') as HTMLButtonElement | null;
	const input = $('#kk-search-input') as HTMLInputElement | null;
	if (!form || !toggle || !input) {
		return;
	}
	const open = (): void => {
		form.classList.add('is-open');
		toggle.setAttribute('aria-expanded', 'true');
		input.focus();
	};
	const close = (refocus: boolean): void => {
		form.classList.remove('is-open');
		toggle.setAttribute('aria-expanded', 'false');
		input.value = '';
		if (refocus) {
			toggle.focus();
		}
	};
	toggle.addEventListener('click', () => {
		if (form.classList.contains('is-open')) {
			close(true);
		} else {
			open();
		}
	});
	input.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault();
			close(true);
		}
	});
	document.addEventListener('click', (event) => {
		if (form.classList.contains('is-open') && !form.contains(event.target as Node)) {
			close(false);
		}
	});
}

/* ------------------------------------------------------------------ */
/* Ticker (bouncing fade rotation)                                     */
/* ------------------------------------------------------------------ */

/**
 * Horizontal sliders (hero slider, highlights slider): every
 * [data-kk-slider] is a scroll-snap row; the prev/next buttons in
 * the same section header scroll it by one child (one slide for the
 * hero, one card for highlights). The buttons live in the section
 * head, so the slider is found via the closest section.
 */
function initSliders(): void {
	document.querySelectorAll<HTMLElement>('[data-kk-slider]').forEach((slider) => {
		const section = slider.closest('section');
		if (!section) {
			return;
		}
		const prev = section.querySelector<HTMLButtonElement>('.kk-slider-prev');
		const next = section.querySelector<HTMLButtonElement>('.kk-slider-next');
		const step = (): number => {
			const first = slider.firstElementChild as HTMLElement | null;
			if (!first) {
				return slider.clientWidth;
			}
			const gap = Number.parseFloat(getComputedStyle(slider).columnGap || '0') || 0;
			return first.offsetWidth + gap;
		};
		if (prev && next) {
			prev.addEventListener('click', () => {
				slider.scrollBy({ left: -step(), behavior: 'smooth' });
			});
			next.addEventListener('click', () => {
				slider.scrollBy({ left: step(), behavior: 'smooth' });
			});
		}
		// Auto slide: data-kk-auto-slide="ms" advances one step on a
		// timer and loops back to the start; pauses while the
		// pointer is over the slider so it can be read.
		const autoMs = Number(slider.getAttribute('data-kk-auto-slide') ?? '0') || 0;
		if (autoMs >= 1000) {
			let paused = false;
			const advance = (): void => {
				if (paused || document.hidden) {
					return;
				}
				const max = slider.scrollWidth - slider.clientWidth;
				if (slider.scrollLeft + step() >= max - 1) {
					slider.scrollTo({ left: 0, behavior: 'smooth' });
				} else {
					slider.scrollBy({ left: step(), behavior: 'smooth' });
				}
			};
			const timer = window.setInterval(advance, autoMs);
			slider.addEventListener('pointerenter', () => {
				paused = true;
			});
			slider.addEventListener('pointerleave', () => {
				paused = false;
			});
			slider.addEventListener('focusin', () => {
				paused = true;
			});
			slider.addEventListener('focusout', () => {
				paused = false;
			});
			// Stop the timer when the slider leaves the page.
			const observer = new MutationObserver(() => {
				if (!slider.isConnected) {
					window.clearInterval(timer);
					observer.disconnect();
				}
			});
			observer.observe(document.body, { childList: true, subtree: true });
		}
	});
}

function initTicker(): void {
	const list = $('#kk-ticker-list');
	if (!list) {
		return;
	}
	const items = $all<HTMLElement>('.kk-ticker-item', list);
	if (items.length <= 1) {
		return;
	}	let current = 0;
	let timer: number | null = null;
	const toggle = $('#kk-ticker-toggle') as HTMLButtonElement | null;
	const speedSeconds = Math.min(120, Math.max(2, Number(list.getAttribute('data-speed') ?? '5') || 5));

	function show(index: number): void {
		const previous = items[current];
		current = (index + items.length) % items.length;
		const next = items[current];
		if (previous === next) {
			return;
		}
		previous.classList.remove('is-active');
		previous.classList.add('is-leaving');
		window.setTimeout(() => previous.classList.remove('is-leaving'), 450);
		next.classList.add('is-active');
	}

	function start(): void {
		stop();
		timer = window.setInterval(() => show(current + 1), speedSeconds * 1000);
	}

	function stop(): void {
		if (timer !== null) {
			window.clearInterval(timer);
			timer = null;
		}
	}

	const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	if (!reduced) {
		start();
	}

	$('#kk-ticker-next')?.addEventListener('click', () => {
		show(current + 1);
		start();
	});
	$('#kk-ticker-prev')?.addEventListener('click', () => {
		show(current - 1);
		start();
	});
	if (toggle) {
		toggle.addEventListener('click', () => {
			const paused = toggle.classList.toggle('is-paused');
			toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
			toggle.setAttribute('aria-label', paused ? 'Resume headlines' : 'Pause headlines');
			if (paused) {
				stop();
			} else {
				start();
			}
		});
	}
	document.addEventListener('visibilitychange', () => {
		if (document.hidden) {
			stop();
		} else if (!toggle?.classList.contains('is-paused') && !reduced) {
			start();
		}
	});
}

/* ------------------------------------------------------------------ */
/* Live clock (top bar)                                                 */
/* ------------------------------------------------------------------ */

/*
 * The server renders the first paint in the resolved time zone; this
 * keeps it ticking. In "device" mode the time zone is the visitor's
 * own, so no timeZone option is passed. The separator replaces only
 * the colon literals between the time parts, never the space before
 * an AM/PM marker.
 */
function initClock(): void {
	const clock = $('#kk-clock') as HTMLTimeElement | null;
	if (!clock) {
		return;
	}
	const mode = clock.getAttribute('data-mode') ?? 'site';
	const zone = clock.getAttribute('data-timezone') ?? '';
	const seconds = clock.getAttribute('data-seconds') === '1';
	const hour12 = clock.getAttribute('data-24h') !== '1';
	const separator = clock.getAttribute('data-separator') === '.' ? '.' : ':';

	const render = (): void => {
		try {
			const options: Intl.DateTimeFormatOptions = {
				hour: 'numeric',
				minute: '2-digit',
				hour12,
			};
			if (seconds) {
				options.second = '2-digit';
			}
			if (mode !== 'device' && zone !== '') {
				options.timeZone = zone;
			}
			const parts = new Intl.DateTimeFormat(undefined, options).formatToParts(new Date());
			clock.textContent = parts
				.map((part) => (part.type === 'literal' && part.value === ':' ? separator : part.value))
				.join('');
		} catch {
			// An unusable time zone leaves the server-rendered time in place.
		}
	};

	render();
	window.setInterval(render, seconds ? 1000 : 15000);
}

/* ------------------------------------------------------------------ */
/* Layout switcher (grid / list / newsletter) — Featured is NOT a
 * latest-news layout; it belongs to the category blocks only.      */
/* ------------------------------------------------------------------ */

/* Read the saved layout from the `kk_layout` cookie (the value the
 * server renders from). Returns null when there is no valid cookie. */
function readLayoutCookie(): 'grid' | 'list' | 'newsletter' | null {
	const match = document.cookie.match(/(?:^|;)\s*kk_layout=([^;]*)/);
	if (!match) {
		return null;
	}
	const value = decodeURIComponent(match[1] ?? '');
	return value === 'grid' || value === 'list' || value === 'newsletter'
		? value
		: null;
}

function initLayoutSwitcher(): void {
	const container = $('#kk-latest-items');
	if (!container) {
		return;
	}
	const items = container;
	function applyLayout(layout: string): void {
		items.classList.remove('kk-layout-grid', 'kk-layout-list', 'kk-layout-newsletter');
		items.classList.add(`kk-layout-${layout}`);
		// The newsletter subscribe block is rendered hidden inside the
		// container for grid/list markup; reveal it only for newsletter.
		$all('.kk-newsletter-subscribe').forEach((block) => {
			block.toggleAttribute('hidden', layout !== 'newsletter');
		});
		$all<HTMLButtonElement>('[data-kk-layout]').forEach((other) => {
			const active = other.dataset.kkLayout === layout;
			other.classList.toggle('is-active', active);
			other.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
	}
	const stored = window.localStorage.getItem(LAYOUT_KEY);
	/* The server already rendered the saved layout from the cookie,
	 * so on load we only sync the active button — but re-applying
	 * the stored layout here keeps the switcher correct even when
	 * the server rendered the Customizer default (e.g. the cookie
	 * was set after the HTML was cached). The cookie is the source
	 * the server reads; localStorage is kept for backwards
	 * compatibility with the earlier switcher. */
	const saved = stored === 'grid' || stored === 'list' || stored === 'newsletter'
		? stored
		: readLayoutCookie();
	if (saved !== null) {
		applyLayout(saved);
	}
	$all<HTMLButtonElement>('[data-kk-layout]').forEach((button) => {
		button.addEventListener('click', () => {
			const layout = button.dataset.kkLayout;
			if (layout !== 'grid' && layout !== 'list' && layout !== 'newsletter') {
				return;
			}
			window.localStorage.setItem(LAYOUT_KEY, layout);
			/* A real cookie so the server can render the saved
			 * layout on the next page load (first paint, no flash). */
			document.cookie = `kk_layout=${layout}; path=/; max-age=31536000; SameSite=Lax`;
			applyLayout(layout);
		});
	});
}

/* ------------------------------------------------------------------ */
/* Bookmarks (IndexedDB only)                                          */
/* ------------------------------------------------------------------ */

function openBookmarksDb(): Promise<IDBDatabase> {
	return new Promise((resolve, reject) => {
		const request = window.indexedDB.open(DB_NAME, DB_VERSION);
		request.onupgradeneeded = () => {
			const db = request.result;
			if (!db.objectStoreNames.contains(STORE_NAME)) {
				db.createObjectStore(STORE_NAME, { keyPath: 'postId' });
			}
		};
		request.onsuccess = () => resolve(request.result);
		request.onerror = () => reject(request.error);
	});
}

async function getAllBookmarks(): Promise<BookmarkEntry[]> {
	const db = await openBookmarksDb();
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readonly');
		const request = tx.objectStore(STORE_NAME).getAll();
		request.onsuccess = () => resolve((request.result as BookmarkEntry[]).sort((a, b) => b.createdAt - a.createdAt));
		request.onerror = () => reject(request.error);
		tx.oncomplete = () => db.close();
	});
}

async function getBookmark(postId: number): Promise<BookmarkEntry | undefined> {
	const db = await openBookmarksDb();
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readonly');
		const request = tx.objectStore(STORE_NAME).get(postId);
		request.onsuccess = () => resolve(request.result as BookmarkEntry | undefined);
		request.onerror = () => reject(request.error);
		tx.oncomplete = () => db.close();
	});
}

async function putBookmark(entry: BookmarkEntry): Promise<void> {
	const db = await openBookmarksDb();
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readwrite');
		tx.objectStore(STORE_NAME).put(entry);
		tx.oncomplete = () => {
			db.close();
			resolve();
		};
		tx.onerror = () => reject(tx.error);
	});
}

async function deleteBookmark(postId: number): Promise<void> {
	const db = await openBookmarksDb();
	return new Promise((resolve, reject) => {
		const tx = db.transaction(STORE_NAME, 'readwrite');
		tx.objectStore(STORE_NAME).delete(postId);
		tx.oncomplete = () => {
			db.close();
			resolve();
		};
		tx.onerror = () => reject(tx.error);
	});
}

function syncBookmarkButton(button: HTMLButtonElement, saved: boolean): void {
	button.setAttribute('aria-pressed', saved ? 'true' : 'false');
	button.setAttribute('aria-label', saved ? 'Remove bookmark' : 'Save bookmark');
	const label = button.querySelector('.kk-bookmark-label');
	if (label) {
		label.textContent = saved ? 'Saved' : 'Save';
	}
}

function entryFromButton(button: HTMLButtonElement): BookmarkEntry {
	return {
		postId: Number(button.dataset.postId ?? 0),
		title: button.dataset.postTitle ?? '',
		url: button.dataset.postUrl ?? '',
		excerpt: button.dataset.postExcerpt ?? '',
		thumbnail: button.dataset.postThumbnail ?? '',
		note: '',
		createdAt: Date.now(),
	};
}

async function initBookmarkButtons(): Promise<void> {
	const buttons = $all<HTMLButtonElement>('[data-kk-bookmark]');
	if (buttons.length === 0) {
		return;
	}
	for (const button of buttons) {
		const postId = Number(button.dataset.postId ?? 0);
		if (!postId) {
			continue;
		}
		try {
			const existing = await getBookmark(postId);
			syncBookmarkButton(button, Boolean(existing));
		} catch {
			// IndexedDB unavailable (private mode, etc.): leave the button inert.
			button.disabled = true;
		}
		button.addEventListener('click', async () => {
			const saved = button.getAttribute('aria-pressed') === 'true';
			try {
				if (saved) {
					await deleteBookmark(postId);
					syncBookmarkButton(button, false);
				} else {
					const existing = await getBookmark(postId);
					const entry = entryFromButton(button);
					if (existing) {
						entry.note = existing.note;
						entry.createdAt = existing.createdAt;
					}
					await putBookmark(entry);
					syncBookmarkButton(button, true);
				}
			} catch {
				// Fail silently: the button simply does not change state.
			}
		});
	}
}

function escapeHtml(value: string): string {
	return value
		.replace(/&/g, '&amp;')
		.replace(/</g, '&lt;')
		.replace(/>/g, '&gt;')
		.replace(/"/g, '&quot;')
		.replace(/'/g, '&#39;');
}

async function renderBookmarksPage(): Promise<void> {
	const list = $('#kk-bookmarks');
	const empty = $('#kk-bookmarks-empty');
	if (!list) {
		return;
	}
	let entries: BookmarkEntry[] = [];
	try {
		entries = await getAllBookmarks();
	} catch {
		entries = [];
	}
	if (entries.length === 0) {
		list.innerHTML = '';
		if (empty) {
			empty.hidden = false;
		}
		return;
	}
	if (empty) {
		empty.hidden = true;
	}
	list.innerHTML = entries
		.map(
			(entry) => `
			<article class="kk-bookmark-item" data-post-id="${entry.postId}">
				${entry.thumbnail ? `<a class="kk-bookmark-item-media" href="${escapeHtml(entry.url)}"><img src="${escapeHtml(entry.thumbnail)}" alt="" loading="lazy"></a>` : '<span class="kk-bookmark-item-media"></span>'}
				<div class="kk-bookmark-item-body">
					<h2 class="kk-bookmark-item-title"><a href="${escapeHtml(entry.url)}">${escapeHtml(entry.title)}</a></h2>
					${entry.excerpt ? `<p class="kk-bookmark-item-excerpt">${escapeHtml(entry.excerpt)}</p>` : ''}
					<textarea class="kk-bookmark-item-note" data-kk-note rows="2" placeholder="Add a note…" aria-label="Note for this bookmark">${escapeHtml(entry.note)}</textarea>
					<div class="kk-bookmark-item-actions">
						<button type="button" class="kk-icon-button" data-kk-note-save>Save note</button>
						<button type="button" class="kk-icon-button" data-kk-note-remove>Remove</button>
					</div>
				</div>
			</article>`,
		)
		.join('');

	$all<HTMLElement>('.kk-bookmark-item', list).forEach((item) => {
		const postId = Number(item.dataset.postId ?? 0);
		const noteField = item.querySelector<HTMLTextAreaElement>('[data-kk-note]');
		item.querySelector('[data-kk-note-save]')?.addEventListener('click', async () => {
			const current = await getBookmark(postId);
			if (current && noteField) {
				current.note = noteField.value;
				await putBookmark(current);
			}
		});
		item.querySelector('[data-kk-note-remove]')?.addEventListener('click', async () => {
			await deleteBookmark(postId);
			item.remove();
			if (list.querySelectorAll('.kk-bookmark-item').length === 0 && empty) {
				empty.hidden = false;
			}
		});
	});
}

function initBookmarks(): void {
	const data = getKkData();
	if (data.isBookmarksEnabled === false) {
		return;
	}
	if (!('indexedDB' in window)) {
		return;
	}
	void initBookmarkButtons();
	void renderBookmarksPage();
}

/* ------------------------------------------------------------------ */
/* PWA install                                                         */
/* ------------------------------------------------------------------ */

interface BeforeInstallPromptEvent extends Event {
	prompt: () => Promise<void>;
	userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

function initPwa(): void {
	const data = getKkData();
	if (data.isPwaEnabled === false) {
		return;
	}
	const installButton = $('#kk-pwa-install') as HTMLButtonElement | null;
	const installWrap = document.querySelector('[data-kk-pwa-button]') as HTMLElement | null;
	const promptModal = $('#kk-install-prompt');
	let deferredPrompt: BeforeInstallPromptEvent | null = null;
	let installShown = false;
	let interacted = false;

	/* The install banner only appears once the reader has interacted
	 * with the page (scroll, hover, click, tap, key) — never on load.
	 * beforeinstallprompt may fire before or after that first
	 * interaction, so track both and show when both have happened. */
	function showInstall(): void {
		if (installShown) {
			return;
		}
		installShown = true;
		if (installButton) {
			installButton.hidden = false;
		}
		if (installWrap) {
			if (installWrap.classList.contains('kk-pwa-banner')) {
				/* Banner above the header: start collapsed (0fr) and
				 * expand a reflow later, so the grid-rows transition
				 * runs and the header slides down with the banner
				 * instead of jumping down instantly. */
				installWrap.classList.add('is-collapsed');
				installWrap.hidden = false;
				void installWrap.offsetHeight;
				installWrap.classList.remove('is-collapsed');
			} else {
				installWrap.hidden = false;
			}
		}
		if (promptModal && !window.localStorage.getItem(INSTALL_SEEN_KEY)) {
			// Delay comes from the Customizer (kk_pwa_prompt_delay, in
			// seconds); 1500ms is the shipped default when it is missing.
			const delay = Math.min(60000, Math.max(0, data.pwaPromptDelayMs ?? 1500));
			window.setTimeout(() => openModal(promptModal), delay);
		}
	}

	function onFirstInteraction(): void {
		interacted = true;
		if (deferredPrompt) {
			showInstall();
		}
	}

	/* Any interaction counts: scroll, hover, click, tap, key. Once
	 * only — after the first one the banner is either shown or armed. */
	const interactionEvents = ['scroll', 'pointerover', 'click', 'touchstart', 'keydown', 'pointerdown'];
	interactionEvents.forEach((name) => {
		window.addEventListener(name, onFirstInteraction, { once: true, passive: true });
	});

	window.addEventListener('beforeinstallprompt', (event) => {
		event.preventDefault();
		deferredPrompt = event as BeforeInstallPromptEvent;
		if (interacted) {
			showInstall();
		}
	});

	async function runPrompt(): Promise<void> {
		if (!deferredPrompt) {
			return;
		}
		await deferredPrompt.prompt();
		await deferredPrompt.userChoice;
		deferredPrompt = null;
		if (installButton) {
			installButton.hidden = true;
		}
	}

	// Dismiss (X on the banner/float): hide it for an hour. The
	// server reads the same cookie and keeps the button hidden until
	// the hour passes.
	$all<HTMLButtonElement>('[data-kk-pwa-dismiss]').forEach((dismiss) => {
		dismiss.addEventListener('click', () => {
			const wrap = dismiss.closest('[data-kk-pwa-button]');
			if (wrap instanceof HTMLElement) {
				if (wrap.classList.contains('kk-pwa-banner')) {
					/* Collapse first so the header slides back up
					 * with the banner, then hide it once the
					 * 0.3s transition is done. */
					wrap.classList.add('is-collapsed');
					window.setTimeout(() => {
						wrap.hidden = true;
					}, 300);
				} else {
					wrap.hidden = true;
				}
			}
			const secure = window.location.protocol === 'https:' ? '; Secure' : '';
			document.cookie = `koperasikita_pwa_button_dismissed=${Math.floor(Date.now() / 1000)}; Path=/; Max-Age=3600; SameSite=Lax${secure}`;
		});
	});

	installButton?.addEventListener('click', () => {
		void runPrompt();
	});
	$('#kk-install-confirm')?.addEventListener('click', () => {
		if (promptModal) {
			closeModal(promptModal);
		}
		window.localStorage.setItem(INSTALL_SEEN_KEY, '1');
		void runPrompt();
	});
	promptModal?.querySelectorAll('[data-kk-modal-close]').forEach((closer) => {
		closer.addEventListener('click', () => {
			window.localStorage.setItem(INSTALL_SEEN_KEY, '1');
		});
	});
}

/* ------------------------------------------------------------------ */
/* Mobile bottom nav active state + back to top                        */
/* ------------------------------------------------------------------ */

function initBottomNav(): void {
	const nav = document.querySelector('.kk-bottom-nav');
	if (!nav) {
		return;
	}
	document.body.classList.add('has-bottom-nav');
	const currentUrl = window.location.href.split(/[?#]/)[0];
	$all<HTMLAnchorElement>('.kk-bottom-nav-menu a').forEach((link) => {
		const href = link.href.split(/[?#]/)[0];
		if (href === currentUrl) {
			link.classList.add('is-active');
			link.setAttribute('aria-current', 'page');
		}
	});
}

function initBackToTop(): void {
	const button = $('#kk-back-to-top') as HTMLButtonElement | null;
	if (!button) {
		return;
	}
	const onScroll = (): void => {
		button.hidden = window.scrollY < 400;
	};
	window.addEventListener('scroll', onScroll, { passive: true });
	onScroll();
	button.addEventListener('click', () => {
		window.scrollTo({ top: 0, behavior: 'smooth' });
	});
}

/* ------------------------------------------------------------------ */
/* Article share: copy link                                            */
/* ------------------------------------------------------------------ */

function initShareCopy(): void {
	const button = $('.kk-share-copy') as HTMLButtonElement | null;
	if (!button) {
		return;
	}
	const status = $('.kk-share-status');
	const url = button.dataset.shareUrl ?? '';
	if (!url) {
		return;
	}
	let timer = 0;
	const showMessage = (message: string, isError: boolean): void => {
		if (!status) {
			return;
		}
		status.textContent = message;
		status.classList.toggle('is-error', isError);
		window.clearTimeout(timer);
		timer = window.setTimeout(() => {
			status.textContent = '';
			status.classList.remove('is-error');
		}, 3000);
	};
	// Fallback for non-secure contexts and older browsers where the
	// async Clipboard API is unavailable.
	const copyWithFallback = (text: string): boolean => {
		const textarea = document.createElement('textarea');
		textarea.value = text;
		textarea.setAttribute('readonly', '');
		textarea.style.position = 'fixed';
		textarea.style.opacity = '0';
		document.body.appendChild(textarea);
		textarea.select();
		let ok = false;
		try {
			ok = document.execCommand('copy');
		} catch {
			ok = false;
		}
		document.body.removeChild(textarea);
		return ok;
	};
	button.addEventListener('click', () => {
		const success = button.dataset.copySuccess ?? 'Link copied';
		const failure = button.dataset.copyError ?? 'The link could not be copied.';
		const done = (ok: boolean): void => showMessage(ok ? success : failure, !ok);
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(url).then(
				() => done(true),
				() => done(copyWithFallback(url)),
			);
		} else {
			done(copyWithFallback(url));
		}
	});
}

/* ------------------------------------------------------------------ */
/* Boot                                                                */
/* ------------------------------------------------------------------ */

document.addEventListener('DOMContentLoaded', () => {
	initColorScheme();
	initMobileNav();
	initModals();
	initExpandingSearch();
	initTicker();
	initSliders();
	initClock();
	initLayoutSwitcher();
	initBookmarks();
	initPwa();
	initBottomNav();
	initBackToTop();
	initShareCopy();
});
