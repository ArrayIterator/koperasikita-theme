/**
 * Koperasi Kita — archive pagination loader (load-more).
 *
 * Reads the current archive query from the loader's data attributes
 * (rendered server-side by Features\Pagination), fetches the next
 * page from the koperasikita/v1/posts endpoint, and appends the
 * server-rendered cards to the same grid page one used. A failed
 * fetch leaves the button enabled so the reader can retry.
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
	const grid = loader
		.closest('.kk-content-area')
		?.querySelector<HTMLElement>('.kk-card-grid');
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
	const perPage = Math.min(20, Math.max(1, Number(loader.getAttribute('data-per-page') ?? '10') || 10));
	let page = Math.max(1, Number(loader.getAttribute('data-page') ?? '1') || 1);
	let loading = false;

	const status = loader.querySelector<HTMLElement>('[data-kk-pagination-status]');
	const end = loader.querySelector<HTMLElement>('[data-kk-pagination-end]');
	const button = loader.querySelector<HTMLButtonElement>('[data-kk-load-more]');

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
		loading = true;
		if (button) {
			button.disabled = true;
		}
		setStatus('Loading…');
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
			setStatus('');
			if (!data.hasMore || page >= maxPages) {
				finish();
			}
		} catch {
			setStatus('Could not load more posts. Please try again.');
		} finally {
			loading = false;
			if (button && page < maxPages) {
				button.disabled = false;
			}
		}
	};

	if (mode === 'load-more') {
		button?.addEventListener('click', () => {
			void loadNext();
		});
	}
}

document.addEventListener('DOMContentLoaded', initPagination);
