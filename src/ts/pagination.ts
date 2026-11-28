/**
 * Koperasi Kita - archive pagination loader (load-more).
 *
 * Reads the current archive query from the loader's data attributes
 * (rendered server-side by Features\Pagination), fetches the next
 * page from the koperasikita/v1/posts endpoint, and appends the
 * server-rendered cards to the same grid page one used. A failed
 * fetch leaves the link enabled so the reader can retry.
 *
 * The cap counts Load More requests, not pages: each click loads
 * one more page, from wherever the reader is, so the cap is
 * reached after that many loads even when the reader started on
 * a later page. Once the cap is reached the click is left alone
 * so it navigates to the next page instead of showing a
 * pagination. When there are no more pages the button hides
 * instead of navigating to a page that does not exist, and a
 * request that answers 404 hides it too.
 */

export {};

interface PostsData {
	html: string;
	page: number;
	pages: number;
	maxPages: number;
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
	let maxPages = Math.max(1, Number(loader.getAttribute('data-max-pages') ?? '1') || 1);
	const loadMoreMaxRequests = Math.max(0, Number(loader.getAttribute('data-load-more-max') ?? '0') || 0);
	const loadingStyle = loader.getAttribute('data-loading-style') ?? 'icon-text';
	const loadingLabel = loader.getAttribute('data-loading-label') ?? 'Loading...';
	const perPage = Math.min(100, Math.max(1, Number(loader.getAttribute('data-per-page') ?? '10') || 10));
	let page = Math.max(1, Number(loader.getAttribute('data-page') ?? '1') || 1);
	let requests = 0;
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
			label.textContent = loadingLabel;
		} else {
			if (icon) {
				icon.hidden = false;
			}
			label.textContent = loadingLabel;
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
		/* The cap counts Load More requests, not pages: each click
		 * loads one more page, from wherever the reader is, so the
		 * cap is reached after that many loads even when the reader
		 * started on a later page. */
		if (loadMoreMaxRequests > 0 && requests >= loadMoreMaxRequests) {
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
			/* A 404 means the requested page does not exist: nothing
			 * more to load, so hide the button instead of showing
			 * an error. */
			if (response.status === 404) {
				finish();
				return;
			}
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
			requests += 1;
			page = data.page;
			/* The response carries the max page count: refresh the
			 * client-side max from it, so the no-next-page check
			 * below uses the response's max, not only the value
			 * the server rendered on page one. */
			maxPages = Math.max(1, data.maxPages || maxPages);
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
			/* No more pages: hide the button instead of navigating
			 * to a page that does not exist. */
			if (page >= maxPages) {
				event.preventDefault();
				finish();
				return;
			}
			/* Cap reached: do not intercept - the link navigates to
			 * the next page. Otherwise load it by AJAX. */
			if (loadMoreMaxRequests > 0 && requests >= loadMoreMaxRequests) {
				return;
			}
			event.preventDefault();
			void loadNext();
		});
	}
}

document.addEventListener('DOMContentLoaded', initPagination);
