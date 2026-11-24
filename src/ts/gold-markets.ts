/**
 * Koperasi Kita — gold prices slider and markets strip.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when the Markets feature is enabled (window.kkData.isMarketsEnabled);
 * a disabled feature loads neither this script nor any markets markup,
 * so there is nothing to guard against beyond the flag.
 *
 * The gold slider auto-advances on a timer, pauses on hover/focus, when
 * the tab is hidden, and when the user presses pause. Prev/next wrap
 * around, dots jump to a slide, and a horizontal swipe changes slides —
 * the controls are real buttons with 44px touch targets for Android.
 * A slider with a single slide has no controls and no timer.
 */

export {};

interface MarketsKkData {
	isMarketsEnabled?: boolean;
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

function initGoldSlider(slider: HTMLElement): void {
	const slides = $all<HTMLElement>('[data-kk-gold-slide]', slider);
	if (slides.length <= 1) {
		return;
	}

	const dots = $all<HTMLButtonElement>('[data-kk-gold-dot]', slider);
	const prevButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-prev]');
	const nextButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-next]');
	const toggleButton = slider.querySelector<HTMLButtonElement>('[data-kk-gold-toggle]');
	const pauseIcon = toggleButton?.querySelector('.kk-widget-toggle-pause') as HTMLElement | null;
	const playIcon = toggleButton?.querySelector('.kk-widget-toggle-play') as HTMLElement | null;

	const parsedInterval = Number(slider.dataset.interval ?? 5000);
	const interval = Number.isFinite(parsedInterval) && parsedInterval >= 2000 ? parsedInterval : 5000;

	let current = 0;
	let timer: number | null = null;
	let userPaused = false;
	let hoverPaused = false;

	function show(index: number): void {
		const previous = slides[current];
		current = (index + slides.length) % slides.length;
		const next = slides[current];
		if (previous === next) {
			return;
		}
		previous.classList.remove('is-active');
		next.classList.add('is-active');
		dots.forEach((dot, dotIndex) => {
			const active = dotIndex === current;
			dot.classList.toggle('is-active', active);
			dot.setAttribute('aria-current', active ? 'true' : 'false');
		});
	}

	function stop(): void {
		if (timer !== null) {
			window.clearInterval(timer);
			timer = null;
		}
	}

	function start(): void {
		if (userPaused || hoverPaused || document.hidden) {
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
		toggleButton.setAttribute('aria-label', userPaused ? 'Play gold prices' : 'Pause gold prices');
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

	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	if (!reducedMotion) {
		start();
	}
}

function initGoldMarkets(): void {
	if (getKkData().isMarketsEnabled === false) {
		return;
	}
	$all<HTMLElement>('[data-kk-gold-slider]').forEach((slider) => initGoldSlider(slider));
}

document.addEventListener('DOMContentLoaded', initGoldMarkets);
