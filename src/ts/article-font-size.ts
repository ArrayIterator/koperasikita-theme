/**
 * Koperasi Kita - article text size control.
 *
 * Standalone module, auto-initialised on DOMContentLoaded. It only runs
 * when a `[data-kk-font-size]` control is on the page - the control is
 * injected by the `the_content` filter on singular post/page/agenda
 * views, so this file is only enqueued there. Anything else on the site
 * never loads it, and even if it did, there is nothing for it to do.
 *
 * The server renders the article with `--article-font-size` already set
 * (cookie size, or the Customizer default), so the first paint is
 * correct and this script only handles live changes: A- / A+ move the
 * size by 1px (clamped 8-24), Reset returns to the Customizer default
 * and clears the cookie. The choice is stored in a cookie for one
 * year, so the next page load renders it server-side. The decrease /
 * increase buttons disable at the limits, and the current size is
 * announced via the live region on the output element.
 */

export {};

const COOKIE = 'koperasikita_article_font_size';
const MIN = 8;
const MAX = 24;

function clamp(size: number): number {
	return Math.min(MAX, Math.max(MIN, size));
}

function setCookie(size: number): void {
	document.cookie = `${COOKIE}=${size}; path=/; max-age=31536000; SameSite=Lax`;
}

function deleteCookie(): void {
	document.cookie = `${COOKIE}=; path=/; max-age=0; SameSite=Lax`;
}

function applySize(control: HTMLElement, size: number): void {
	const clamped = clamp(size);
	const article = control.closest('article');
	if (article) {
		article.style.setProperty('--article-font-size', `${clamped}px`);
	}
	const value = control.querySelector<HTMLElement>('[data-kk-font-size-value]');
	if (value) {
		value.textContent = `${clamped}px`;
	}
	control.dataset.kkFontSizeCurrent = String(clamped);
	syncButtons(control, clamped);
}

function syncButtons(control: HTMLElement, size: number): void {
	const decrease = control.querySelector<HTMLButtonElement>('[data-kk-font-size-decrease]');
	const increase = control.querySelector<HTMLButtonElement>('[data-kk-font-size-increase]');
	if (decrease) {
		decrease.disabled = size <= MIN;
	}
	if (increase) {
		increase.disabled = size >= MAX;
	}
}

function currentSize(control: HTMLElement): number {
	const raw = control.dataset.kkFontSizeCurrent ?? '';
	const parsed = Number.parseInt(raw, 10);
	return Number.isNaN(parsed) ? MIN : clamp(parsed);
}

function defaultSize(control: HTMLElement): number {
	const raw = control.dataset.kkFontSizeDefault ?? '';
	const parsed = Number.parseInt(raw, 10);
	return Number.isNaN(parsed) ? MIN : clamp(parsed);
}

function initControl(control: HTMLElement): void {
	const decrease = control.querySelector<HTMLButtonElement>('[data-kk-font-size-decrease]');
	const increase = control.querySelector<HTMLButtonElement>('[data-kk-font-size-increase]');
	const reset = control.querySelector<HTMLButtonElement>('[data-kk-font-size-reset]');

	// Start from the server-rendered size (already clamped there);
	// this only sets the initial button states.
	syncButtons(control, currentSize(control));

	decrease?.addEventListener('click', () => {
		const next = clamp(currentSize(control) - 1);
		if (next === currentSize(control)) {
			return;
		}
		applySize(control, next);
		setCookie(next);
	});

	increase?.addEventListener('click', () => {
		const next = clamp(currentSize(control) + 1);
		if (next === currentSize(control)) {
			return;
		}
		applySize(control, next);
		setCookie(next);
	});

	reset?.addEventListener('click', () => {
		applySize(control, defaultSize(control));
		deleteCookie();
	});
}

function initFontSize(): void {
	Array.from(document.querySelectorAll<HTMLElement>('[data-kk-font-size]')).forEach((control) => {
		initControl(control);
	});
}

document.addEventListener('DOMContentLoaded', initFontSize);
