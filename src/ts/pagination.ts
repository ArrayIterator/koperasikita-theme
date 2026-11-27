/**
 * Koperasi Kita - archive pagination loader (load-more).
 *
 * Reads the current archive query from the loader's data attributes
 * (rendered server-side by Features\Pagination), fetches the next
 * page from the koperasikita/v1/posts endpoint, and appends the
 * server-rendered cards to the same grid page one used. A failed
 * fetch leaves the link enabled so the reader can retry.
 *
 * The control is a real link to the next page: the click is
 * intercepted for an AJAX load while there are pages left inside
 * the cap, and once the cap is reached the click is left alone so
 * it navigates to that page instead of showing a pagination.
 */

export {};

interface PostsData {
	html: string;
	page: number;
	pages: number;
	hasMore: boolean;
	perPage: number;
}

interface PostsEnvelope {
	success: boolean;
	data: PostsData | null;
	error: { code: string; message: string } | null;
}

function initPagination(): void {
	const loader = document.querySelector<HTMLElement>('[data-kk-pagination]');
	if (!loader) {
		return;
	}
	const mode = loader.getAttribute('data-mode') ?? 'load-more';
	const endpoint = loader.getAttribute('data-endpoint') ?? '';
	if (endpoint === '') {
		return;
	}
	// The grid the loader appends to: the archive card grid, or the
	// homepage latest-news items (the homepage loader lives inside
	// .kk-home-primary and appends to .kk-latest-items).
	const grid = loader
		.closest('.kk-content-area')
		?.querySelector<HTMLElement>('.kk-card-grid')
		?? loader
			.closest('.kk-home-primary')
			?.querySelector<HTMLElement>('.kk-latest-items');
	if (!grid) {
		return;
	}

	let query: Record<string, string | number> = {};
	try {
		query = JSON.parse(loader.getAttribute('data-query') ?? '{}') as Record<
			string,
			string | number
		>;
	} catch {
		query = {};
	}
	const maxPages = Math.max(1, Number(loader.getAttribute('data-max-pages') ?? '1') || 1);
	const loadMoreMax = Math.max(0, Number(loader.getAttribute('data-load-more-max') ?? '0') || 0);
	const loadingStyle = loader.getAttribute('data-loading-style') ?? 'icon-text';
	const perPage = Math.min(100, Math.max(1, Number(loader.getAttribute('data-per-page') ?? '10') || 10));
	let page = Math.max(1, Number(loader.getAttribute('data-page') ?? '1') || 1);
	let loading = false;

	const status = loader.querySelector<HTMLElement>('[data-kk-pagination-status]');
	const end = loader.querySelector<HTMLElement>('[data-kk-pagination-end]');
	const button = loader.querySelector<HTMLAnchorElement>('[data-kk-load-more]');
	const icon = button?.querySelector<HTMLElement>('.kk-load-more-icon') ?? null;
	const label = button?.querySelector<HTMLElement>('.kk-load-more-label') ?? null;
	const idleLabel = label?.textContent ?? '';

	/* Point the link at a page number, keeping the permalink style
	 * the server rendered: /page/N/ for pretty permalinks, ?paged=N
	 * for plain ones. */
	const nextPageUrl = (href: string, pageNum: number): string => {
		if (/\/page\/\d+\/?/.test(href)) {
			return href.replace(/\/page\/\d+\/?/, `/page/${pageNum}/`);
		}
		try {
			const url = new URL(href, window.location.origin);
			url.searchParams.set('paged', String(pageNum));
			return url.toString();
		} catch {
			return href;
		}
	};

	/* While loading the link shows the spinner icon and/or the
	 * "Loading..." text, per the Customizer loading style. Restored
	 * to the idle label (the custom link text) when done. */
	const setLoading = (active: boolean): void => {
		if (!button || !label) {
			return;
		}
		if (!active) {
			if (icon) {
				icon.hidden = true;
			}
			label.textContent = idleLabel;
			button.removeAttribute('aria-disabled');
			return;
		}
		/* An anchor has no disabled state: aria-disabled marks it
		 * busy for assistive tech, and the loading flag below
		 * ignores further clicks until the fetch settles. */
		button.setAttribute('aria-disabled', 'true');
		if (loadingStyle === 'icon') {
			if (icon) {
				icon.hidden = false;
			}
			label.textContent = '';
		} else if (loadingStyle === 'text') {
			if (icon) {
				icon.hidden = true;
			}
			label.textContent = 'Loading...';
		} else {
			if (icon) {
				icon.hidden = false;
			}
			label.textContent = 'Loading...';
		}
	};

	const setStatus = (text: string): void => {
		if (status) {
			status.textContent = text;
		}
	};

	const finish = (): void => {
		if (button) {
			button.hidden = true;
		}
		if (end) {
			end.hidden = false;
		}
	};

	const loadNext = async (): Promise<void> => {
		if (loading || page >= maxPages) {
			return;
		}
		/* The cap stops AJAX loading even when more pages exist:
		 * the link is left alone from here, so the next click
		 * navigates to the next page instead. The click handler
		 * below is what enforces this - loadNext never runs past
		 * the cap. */
		if (loadMoreMax > 0 && page >= loadMoreMax) {
			return;
		}
		loading = true;
		setLoading(true);
		setStatus('');
		try {
			const params = new URLSearchParams();
			Object.entries(query).forEach(([key, value]) => {
				params.set(key, String(value));
			});
			params.set('page', String(page + 1));
			params.set('per_page', String(perPage));
			const response = await fetch(`${endpoint}?${params.toString()}`);
			const body = (await response.json()) as PostsEnvelope;
			if (!response.ok || !body.success || !body.data) {
				throw new Error(body.error?.message ?? 'Request failed');
			}
			const data = body.data;
			const temp = document.createElement('div');
			temp.innerHTML = data.html;
			while (temp.firstChild) {
				grid.appendChild(temp.firstChild);
			}
			page = data.page;
			/* Keep the link pointing at the page after the one just
			 * loaded, so a later click (past the cap, or with
			 * JavaScript off) lands on the right page. */
			if (button) {
				button.href = nextPageUrl(button.href, page + 1);
			}
			setStatus('');
			if (!data.hasMore || page >= maxPages) {
				finish();
			}
		} catch {
			setStatus('Could not load more posts. Please try again.');
		} finally {
			loading = false;
			setLoading(false);
		}
	};

	if (mode === 'load-more') {
		button?.addEventListener('click', (event) => {
			/* Cap reached: do not intercept - the link navigates to
			 * the next page. Otherwise load it by AJAX. */
			if (loadMoreMax > 0 && page >= loadMoreMax) {
				return;
			}
			if (page >= maxPages) {
				return;
			}
			event.preventDefault();
			void loadNext();
		});
	}
}

document.addEventListener('DOMContentLoaded', initPagination);
