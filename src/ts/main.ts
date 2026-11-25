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

function getStoredScheme(): ColorScheme {
	const stored = window.localStorage.getItem(SCHEME_KEY);
	if (stored === 'light' || stored === 'dark' || stored === 'system') {
		return stored;
	}
	return 'system';
}

function applyScheme(scheme: ColorScheme): void {
	document.documentElement.setAttribute('data-color-scheme', scheme);
	// Every toggle (header on desktop, drawer footer on mobile) stays
	// in sync: same pressed state, same next-mode label.
	$all<HTMLButtonElement>('[data-kk-scheme-toggle]').forEach((toggle) => {
		toggle.setAttribute('aria-pressed', scheme === 'dark' ? 'true' : 'false');
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

	// Sub-menu drill-down: every parent item gets a chevron button
	// after its link. Tapping it opens that sub-menu under the back
	// bar; the back button returns to the top level.
	const chevronSvg =
		'<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>';
	$all<HTMLLIElement>('.kk-primary-menu > li.menu-item-has-children', nav).forEach((item) => {
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
			nav.classList.add('is-drilling');
			item.classList.add('is-active-parent');
			button.setAttribute('aria-expanded', 'true');
			if (backBar) {
				backBar.hidden = false;
			}
			if (backLabel) {
				backLabel.textContent = link.textContent?.trim() ?? '';
			}
			drillToggle = button;
			backButton?.focus();
		});
	});

	const resetDrill = (): void => {
		nav.classList.remove('is-drilling');
		$all('.is-active-parent', nav).forEach((item) => item.classList.remove('is-active-parent'));
		$all<HTMLButtonElement>('.kk-submenu-toggle', nav).forEach((button) => {
			button.setAttribute('aria-expanded', 'false');
		});
		if (backBar) {
			backBar.hidden = true;
		}
		if (backLabel) {
			backLabel.textContent = '';
		}
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
		// Slide the top level back in from the left (AMP-style); the
		// class comes off once the 0.28s animation has run.
		nav.classList.add('is-sliding-back');
		window.setTimeout(() => nav.classList.remove('is-sliding-back'), 300);
		resetDrill();
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

function initSearchDialog(): void {
	const dialog = $('#kk-search-dialog') as HTMLDialogElement | null;
	const openButton = $('#kk-search-open');
	const closeButton = $('#kk-search-close') as HTMLButtonElement | null;
	if (!dialog || !openButton) {
		return;
	}
	const input = dialog.querySelector<HTMLInputElement>('.search-field');
	let lastFocused: HTMLElement | null = null;
	openButton.addEventListener('click', () => {
		lastFocused = document.activeElement as HTMLElement | null;
		document.documentElement.classList.add('kk-search-open');
		if (typeof dialog.showModal === 'function') {
			dialog.showModal();
		} else {
			dialog.setAttribute('open', '');
		}
		input?.focus();
	});
	closeButton?.addEventListener('click', () => dialog.close());
	dialog.addEventListener('click', (event) => {
		// A click on the dialog element itself (not its content) is a
		// backdrop click: the ::backdrop is not a real element, so the
		// target is the dialog when the click lands outside the box.
		if (event.target === dialog) {
			dialog.close();
		}
	});
	dialog.addEventListener('cancel', (event) => {
		// The field owns Escape: clear the query and leave, keep the
		// dialog open. A second Escape (focus outside the field)
		// closes it.
		if (input && document.activeElement === input) {
			event.preventDefault();
			input.value = '';
			input.blur();
		}
	});
	dialog.addEventListener('close', () => {
		document.documentElement.classList.remove('kk-search-open');
		lastFocused?.focus();
		lastFocused = null;
	});
}

/* ------------------------------------------------------------------ */
/* Ticker (bouncing fade rotation)                                     */
/* ------------------------------------------------------------------ */

function initTicker(): void {
	const list = $('#kk-ticker-list');
	if (!list) {
		return;
	}
	const items = $all<HTMLElement>('.kk-ticker-item', list);
	if (items.length <= 1) {
		return;
	}
	let current = 0;
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
/* Layout switcher (grid / list / newsletter)                           */
/* ------------------------------------------------------------------ */

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
	if (stored === 'grid' || stored === 'list' || stored === 'newsletter') {
		applyLayout(stored);
	}
	$all<HTMLButtonElement>('[data-kk-layout]').forEach((button) => {
		button.addEventListener('click', () => {
			const layout = button.dataset.kkLayout;
			if (layout !== 'grid' && layout !== 'list' && layout !== 'newsletter') {
				return;
			}
			window.localStorage.setItem(LAYOUT_KEY, layout);
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

	window.addEventListener('beforeinstallprompt', (event) => {
		event.preventDefault();
		deferredPrompt = event as BeforeInstallPromptEvent;
		if (installButton) {
			installButton.hidden = false;
		}
		if (installWrap) {
			installWrap.hidden = false;
		}
		if (promptModal && !window.localStorage.getItem(INSTALL_SEEN_KEY)) {
			// Delay comes from the Customizer (kk_pwa_prompt_delay, in
			// seconds); 1500ms is the shipped default when it is missing.
			const delay = Math.min(60000, Math.max(0, data.pwaPromptDelayMs ?? 1500));
			window.setTimeout(() => openModal(promptModal), delay);
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
				wrap.hidden = true;
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
	initSearchDialog();
	initTicker();
	initClock();
	initLayoutSwitcher();
	initBookmarks();
	initPwa();
	initBottomNav();
	initBackToTop();
	initShareCopy();
});
