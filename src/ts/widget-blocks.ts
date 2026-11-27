/**
 * Koperasi Kita - widget blocks editor script.
 *
 * Registers the theme widgets as blocks in the "Koperasi Kita" inserter
 * category (the block types themselves, with render callbacks, are
 * registered server-side in inc/Widgets/Blocks.php). Each block gets an
 * edit UI built from a small field schema: the InspectorControls show
 * the widget's own fields (title, count, layout, thumbnails, and the
 * per-widget extras), while the canvas shows a live ServerSideRender
 * preview - the real frontend markup, rendered on the server through
 * the_widget().
 *
 * The category/tag pickers are searchable token fields: one chip
 * per picked term, backed by the core store for search and the
 * localized term lists for browsing, so they always match the
 * site's categories and tags without a page reload.
 */

export {};

interface OptionItem {
	value: string;
	label: string;
}

type ShowWhen = { key: string; value: string | boolean };

type FieldDef =
	| { key: string; kind: 'text'; label: string; help?: string }
	| { key: string; kind: 'textarea'; label: string; help?: string }
	| { key: string; kind: 'number'; label: string; help?: string; min: number; max: number; fallback?: number }
	| { key: string; kind: 'select'; label: string; options: OptionItem[]; showWhen?: ShowWhen; disabledWhenEmpty?: { key: string }; disabledHelp?: string }
	| { key: string; kind: 'toggle'; label: string }
	| { key: string; kind: 'checkboxes'; label: string; options: OptionItem[] }
	| { key: string; kind: 'termsearch'; label: string; help?: string; source: 'categories' | 'tags' | 'options'; options: OptionItem[]; single?: boolean }
	| { key: string; kind: 'locations'; label: string; help?: string }
	| { key: string; kind: 'postsearch'; label: string; help?: string }
	| { key: string; kind: 'data-desk-overrides'; label: string }
	/* A hex colour swatch: a native colour input plus a hex readout
	 * and a clear button (empty = fall back to the theme colour).
	 * showWhen hides the field unless another attribute has the
	 * given value - the Choose Own colours only show for the custom
	 * card style. fallback is the picker starting value while the
	 * stored value is empty; it is never stored itself. */
	| { key: string; kind: 'color'; label: string; help?: string; showWhen?: ShowWhen; fallback?: string };

interface BlockDef {
	slug: string;
	title: string;
	description: string;
	icon: string;
	attributes: Record<string, Record<string, unknown>>;
	fields: FieldDef[];
}

interface CategoryRecord {
	id: number;
	name: string;
	count: number;
}

interface PostTypeRecord {
	title?: {
		rendered?: string;
	};
}

interface WpDataSelect {
	(store: string): {
		getEntityRecords: (
			kind: string,
			name: string,
			query?: Record<string, unknown>,
		) => CategoryRecord[] | null;
		getEntityRecord: (
			kind: string,
			name: string,
			key: number,
		) => PostTypeRecord | null | undefined;
	};
}

interface BlockEditProps {
	attributes: Record<string, unknown>;
	setAttributes: (attributes: Record<string, unknown>) => void;
}

interface Wp {
	blocks: {
		registerBlockType: (
			name: string,
			settings: {
				title: string;
				description: string;
				icon: string;
				category: string;
				attributes: Record<string, Record<string, unknown>>;
				example?: { attributes: Record<string, unknown> };
				edit: (props: BlockEditProps) => unknown;
				save: () => null;
			},
		) => void;
	};
	element: {
		createElement: (
			type: unknown,
			props: Record<string, unknown> | null,
			...children: unknown[]
		) => unknown;
		Fragment: unknown;
		useState: <T>(initial: T) => [T, (next: T | ((prev: T) => T)) => void];
		useRef: <T>(initial: T) => { current: T };
		useEffect: (effect: () => void | (() => void), deps: unknown[]) => void;
	};
	components: {
		TextControl: unknown;
		TextareaControl: unknown;
		SelectControl: unknown;
		ToggleControl: unknown;
		RangeControl: unknown;
		CheckboxControl: unknown;
		FormTokenField: unknown;
		PanelBody: unknown;
		Spinner: unknown;
	};
	blockEditor: {
		InspectorControls: unknown;
		useBlockProps: (props?: Record<string, unknown>) => Record<string, unknown>;
	};
	data: {
		useSelect<T>(mapSelect: (select: WpDataSelect) => T, deps: unknown[]): T;
	};
	serverSideRender: {
		ServerSideRender: unknown;
	};
	i18n: {
		__(text: string, domain?: string): string;
		sprintf(format: string, ...args: Array<string | number>): string;
	};
}

declare const wp: Wp | undefined;

declare const kkWidgetBlocks:
	| {
			blocks?: string[];
			goldBrands?: OptionItem[];
			marketLabels?: OptionItem[];
			marketItems?: OptionItem[];
			categories?: OptionItem[];
			tags?: OptionItem[];
			dataDeskTemplates?: OptionItem[];
			dataDeskSymbols?: OptionItem[];
			dataDeskTemplateMeta?: Record<string, DataDeskTemplateMeta>;
			icons?: OptionItem[];
			ajaxUrl?: string;
			nonce?: string;
	  }
	| undefined;

interface DataDeskTemplateMeta {
	symbol: string;
	days: number;
	hiddenBlocks: string[];
	statLabels: Record<string, string>;
}

interface WeatherLocation {
	name: string;
	latitude: number;
	longitude: number;
}

interface WeatherSearchResult {
	name: string;
	latitude: number;
	longitude: number;
	country: string;
}

function isWeatherLocation(item: unknown): item is WeatherLocation {
	return (
		typeof item === 'object' &&
		item !== null &&
		typeof (item as WeatherLocation).name === 'string' &&
		typeof (item as WeatherLocation).latitude === 'number' &&
		typeof (item as WeatherLocation).longitude === 'number'
	);
}

function isSameWeatherLocation(a: WeatherLocation, b: WeatherLocation): boolean {
	if (a.name.trim().toLowerCase() === b.name.trim().toLowerCase()) {
		return true;
	}
	return Math.abs(a.latitude - b.latitude) < 0.01 && Math.abs(a.longitude - b.longitude) < 0.01;
}

/* The weather locations field: the saved list (name + coordinates
 * with a remove button each) plus a search box that adds places
 * through the theme's own kk_weather_search endpoint - the same
 * geocoding the frontend location picker uses. A real component
 * (not a renderField branch) so it can hold the search state. */
function LocationsField(props: {
	field: { key: string; label: string; help?: string };
	value: unknown;
	set: (key: string, value: unknown) => void;
	api: Wp;
}): unknown {
	const { field, value, set, api } = props;
	const __ = (text: string): string => api.i18n.__(text, 'koperasikita');
	const locations: WeatherLocation[] = Array.isArray(value) ? value.filter(isWeatherLocation) : [];
	const [query, setQuery] = api.element.useState('');
	const [results, setResults] = api.element.useState<WeatherSearchResult[]>([]);
	const [searching, setSearching] = api.element.useState(false);
	const [status, setStatus] = api.element.useState('');
	const timer = api.element.useRef<number | null>(null);

	const search = (nextQuery: string): void => {
		if (timer.current !== null) {
			window.clearTimeout(timer.current);
			timer.current = null;
		}
		if (nextQuery.trim().length < 2) {
			setResults([]);
			setStatus('');
			return;
		}
		setStatus(__('Searching…'));
		timer.current = window.setTimeout(() => {
			const ajaxUrl = kkWidgetBlocks?.ajaxUrl ?? '';
			const nonce = kkWidgetBlocks?.nonce ?? '';
			if (ajaxUrl === '' || nonce === '') {
				setSearching(false);
				setStatus(__('Search is unavailable right now.'));
				return;
			}
			setSearching(true);
			const body = new URLSearchParams({ action: 'kk_weather_search', nonce, query: nextQuery.trim() });
			void fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
				.then((response) => response.json() as Promise<unknown>)
				.then((json) => {
					setSearching(false);
					const data = json as {
						success?: boolean;
						data?: { results?: WeatherSearchResult[] };
					};
					const found = Array.isArray(data.data?.results) ? data.data.results : [];
					setResults(found);
					setStatus(found.length === 0 ? __('No places found. Try another name.') : '');
				})
				.catch(() => {
					setSearching(false);
					setStatus(__('Search is unavailable right now.'));
				});
		}, 300);
	};

	const add = (place: WeatherSearchResult): void => {
		const candidate: WeatherLocation = { name: place.name, latitude: place.latitude, longitude: place.longitude };
		if (locations.some((kept) => isSameWeatherLocation(kept, candidate))) {
			setStatus(`${place.name} ${__('is already in the list.')}`);
			return;
		}
		set(field.key, [...locations, candidate]);
		setResults([]);
		setQuery('');
		setStatus(`${place.name} ${__('added.')}`);
	};

	const rows = locations.map((location, index) =>
		api.element.createElement(
			'li',
			{ key: `${location.name}-${location.latitude}-${location.longitude}`, className: 'kk-locations-row' },
			api.element.createElement(
				'span',
				{ className: 'kk-locations-name' },
				`${location.name} - ${location.latitude}, ${location.longitude}`,
			),
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-locations-remove',
					'aria-label': `${__('Remove')} ${location.name}`,
					onClick: () => set(field.key, locations.filter((_, i) => i !== index)),
				},
				'x',
			),
		),
	);

	const resultButtons = results.map((place) =>
		api.element.createElement(
			'li',
			{ key: `${place.name}-${place.latitude}-${place.longitude}` },
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-locations-result',
					onClick: () => add(place),
				},
				place.country && place.country !== place.name ? `${place.name}, ${place.country}` : place.name,
			),
		),
	);

	return api.element.createElement(
		'div',
		{ key: field.key, className: 'kk-locations-field' },
		api.element.createElement('p', { className: 'kk-locations-label' }, field.label),
		api.element.createElement('ul', { className: 'kk-locations-list' }, ...rows),
		api.element.createElement(api.components.TextControl, {
			label: __('Add a location'),
			help: field.help ?? '',
			value: query,
			onChange: (next: string) => {
				setQuery(next);
				search(next);
			},
		}),
		searching ? api.element.createElement(api.components.Spinner, null) : null,
		resultButtons.length > 0
			? api.element.createElement('ul', { className: 'kk-locations-results' }, ...resultButtons)
			: null,
		status !== ''
			? api.element.createElement('p', { className: 'components-base-control__help' }, status)
			: null,
	);
}

interface LinkSearchResult {
	id: number;
	title: string;
	type: string;
	url: string;
}

function isLinkSearchResult(item: unknown): item is LinkSearchResult {
	return (
		typeof item === 'object' &&
		item !== null &&
		typeof (item as LinkSearchResult).id === 'number' &&
		typeof (item as LinkSearchResult).title === 'string' &&
		typeof (item as LinkSearchResult).type === 'string'
	);
}

/* Decode a title.rendered string (HTML entities, no tags) to plain
 * text for display. A textarea decodes entities without ever
 * parsing the string as markup. */
function decodeTitle(html: string): string {
	const el = document.createElement('textarea');
	el.innerHTML = html;
	return el.value;
}

/* The agenda "Link to" field: the chosen post or page (its resolved
 * title with a "Remove link" button) plus a search box that finds
 * published posts and pages by title through the theme's own
 * kk_agenda_link_search endpoint - the same search the widget form
 * uses. A real component (not a renderField branch) so it can hold
 * the search state and resolve the stored id to a title. */
const EMPTY_RECORDS: CategoryRecord[] = [];

/* Browse cap: under 50 terms the whole list shows; at 50+ only
 * the first 10, so the panel stays scannable. 10 is the cap the
 * user picked (20/25 would do too); typing searches the whole
 * set either way, the cap only limits the initial browse list. */
const BROWSE_FULL_MAX = 50;
const BROWSE_LIMIT = 10;

/* The term search field: picked terms as removable chips plus a
 * search box. With an empty search the cached localized list
 * shows (all of it under 50 terms, the first 10 at 50+), so the
 * list stays browseable; typing searches the WordPress core store
 * (categories and tags) or filters the localized list in the
 * browser (brands). A real component; it holds the search state.
 * A single field picks one term and stores its id as a plain
 * string; the rest store an array of ids. */
function TermSearchField(props: {
	field: { key: string; label: string; help?: string; source: 'categories' | 'tags' | 'options'; options: OptionItem[]; single?: boolean };
	value: unknown;
	set: (key: string, value: unknown) => void;
	api: Wp;
}): unknown {
	const { field, value, set, api } = props;
	const __ = (text: string): string => api.i18n.__(text, 'koperasikita');
	/* Stored ids arrive as strings; older blocks stored numbers.
	 * Normalize both so a stored pick never vanishes. A single
	 * field stores one id as a plain string, not an array. */
	const selected: string[] = field.single === true
		? typeof value === 'string' && value !== ''
			? [value]
			: []
		: Array.isArray(value)
			? value
				.filter((item): item is string | number => typeof item === 'string' || typeof item === 'number')
				.map((item) => String(item))
			: [];
	const [query, setQuery] = api.element.useState('');
	const [debounced, setDebounced] = api.element.useState('');
	const [status, setStatus] = api.element.useState('');
	/* Closed until the search is used: every select is a hidden
	 * dropdown like Select2, so the option list never sits visible
	 * in the inspector and the fields below never move. */
	const [open, setOpen] = api.element.useState(false);
	const timer = api.element.useRef<number | null>(null);
	const fieldRef = api.element.useRef<HTMLElement | null>(null);

	/* A click outside closes the list. Focus leaving the field
	 * closes it too, but a null relatedTarget (a click on plain
	 * text) does not move focus in every browser, so the
	 * outside-click handler decides those. */
	api.element.useEffect(() => {
		const onPointerDown = (event: PointerEvent): void => {
			if (fieldRef.current !== null && !fieldRef.current.contains(event.target as Node | null)) {
				setOpen(false);
			}
		};
		document.addEventListener('pointerdown', onPointerDown);
		return () => {
			document.removeEventListener('pointerdown', onPointerDown);
		};
	}, []);

	/* The core store search only runs for a real query (categories
	 * and tags); short queries filter the localized list in the
	 * browser instead, so typing never waits on the network for
	 * the first characters. */
	const trimmed = debounced.trim();
	const taxonomy = field.source === 'tags' ? 'post_tag' : 'category';
	const searching = (field.source === 'categories' || field.source === 'tags') && trimmed.length >= 2;
	const records = api.data.useSelect(
		(select) =>
			searching
				? select('core').getEntityRecords('taxonomy', taxonomy, { search: trimmed, per_page: 20 })
				: EMPTY_RECORDS,
		[searching, trimmed, taxonomy],
	);

	const selectedSet = new Set(selected);
	const labelFor = (val: string): string => {
		const known = field.options.find((option) => option.value === val);
		// Decode too: a localized label carrying an entity would
		// otherwise show raw on the chip ("&amp;").
		return known !== undefined ? decodeTitle(known.label) : val;
	};

	/* Results: the cached localized list while browsing, the core
	 * store search while typing (categories and tags), a client-side
	 * filter while typing (brands, or a query too short to search).
	 * Already-picked terms never show twice.
	 *
	 * The browse list is the cached variable: field.options is the
	 * localized kkWidgetBlocks list - one PHP get_terms per editor
	 * page load, no cap - so the component never refetches it.
	 * Its length is the true total (the list is never truncated
	 * upstream), which is what the capped count line reports. The
	 * search path is cached the same way: the core store keeps
	 * each resolved query, the selector only re-runs when the
	 * debounced query changes, and the 300ms debounce means one
	 * request per pause, never one per keystroke. */
	let results: OptionItem[] = [];
	let waiting = false;
	let browseCapped = false;
	let browseShown = 0;
	let browseTotal = 0;
	if (trimmed === '') {
		const available = field.options.filter((option) => !selectedSet.has(option.value));
		browseTotal = field.options.length;
		if (browseTotal < BROWSE_FULL_MAX) {
			results = available;
		} else {
			results = available.slice(0, BROWSE_LIMIT);
			browseCapped = true;
		}
		browseShown = results.length;
	} else if (field.source === 'options' || trimmed.length < 2) {
		const needle = trimmed.toLowerCase();
		results = field.options.filter(
			(option) => !selectedSet.has(option.value) && option.label.toLowerCase().includes(needle),
		);
	} else if (records === null) {
		waiting = true;
	} else {
		/* The core store returns term names entity-escaped
		 * ("Bisnis &amp; Keuangan"), so decode them - the browse
		 * list is already plain text and decoding it is a no-op. */
		results = records
			.filter((record) => !selectedSet.has(String(record.id)))
			.map((record) => ({ value: String(record.id), label: decodeTitle(record.name) }));
	}

	const onQueryChange = (next: string): void => {
		setQuery(next);
		if (timer.current !== null) {
			window.clearTimeout(timer.current);
			timer.current = null;
		}
		timer.current = window.setTimeout(() => setDebounced(next), 300);
	};

	/* Only the search box opens the list: focus landing on a chip
	 * remove button or an option must not pop it open. */
	const isSearchInput = (event: FocusEvent | MouseEvent): boolean =>
		event.target instanceof HTMLInputElement;

	const closeOnLeave = (event: FocusEvent): void => {
		const next = event.relatedTarget;
		if (next instanceof Node && fieldRef.current !== null && fieldRef.current.contains(next)) {
			return;
		}
		setOpen(false);
	};

	const closeOnEscape = (event: KeyboardEvent): void => {
		if (event.key !== 'Escape') {
			return;
		}
		setOpen(false);
		fieldRef.current?.querySelector('input')?.blur();
	};

	const add = (option: OptionItem): void => {
		if (selected.includes(option.value)) {
			setStatus(`${option.label} ${__('is already in the list.')}`);
			return;
		}
		if (field.single === true) {
			set(field.key, option.value);
			setOpen(false);
		} else {
			set(field.key, [...selected, option.value]);
			/* Multi picks stay open for the next pick; the search
			 * keeps focus so the list cannot close with the picked
			 * option (see the result button mousedown handler). */
			fieldRef.current?.querySelector('input')?.focus();
		}
		setQuery('');
		setDebounced('');
		setStatus(`${option.label} ${__('added.')}`);
	};

	const remove = (val: string): void => {
		if (field.single === true) {
			set(field.key, '');
		} else {
			set(field.key, selected.filter((item) => item !== val));
		}
	};

	const chips = selected.map((val) => {
		const label = labelFor(val);
		return api.element.createElement(
			'li',
			{ key: val, className: 'kk-termsearch-row' },
			api.element.createElement('span', { className: 'kk-termsearch-name' }, label),
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-termsearch-remove',
					'aria-label': `${__('Remove')} ${label}`,
					onClick: () => remove(val),
				},
				'x',
			),
		);
	});

	const resultButtons = results.map((option) =>
		api.element.createElement(
			'li',
			{ key: option.value },
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-termsearch-result',
					/* A mousedown on the button would move focus off
					 * the search input, and the picked option
					 * unmounts on click: focus would land on the
					 * body, the field would read that as leaving,
					 * and the picker (and, in the editor, the block
					 * selection) would drop. Keeping focus in the
					 * input means the unmount can never strand it. */
					onMouseDown: (event: MouseEvent): void => event.preventDefault(),
					onClick: () => add(option),
				},
				option.label,
			),
		),
	);

	/* The dropdown renders only while open, anchored under the
	 * search box, so a closed picker shows just chips and search.
	 * The help line sits outside the anchor: the list must open
	 * tight under the input, not below the help text. The pick
	 * note lives inside the dropdown too: as an in-flow line under
	 * the control it grew the field on every pick and pushed the
	 * fields below down with it. */
	const dropdown = open
		? api.element.createElement(
				'div',
				{ className: 'kk-termsearch-dropdown' },
				waiting ? api.element.createElement(api.components.Spinner, null) : null,
				resultButtons.length > 0
					? api.element.createElement('ul', { className: 'kk-termsearch-results' }, ...resultButtons)
					: null,
				browseCapped && resultButtons.length > 0
					? api.element.createElement(
							'p',
							{ className: 'components-base-control__help' },
							api.i18n.sprintf(
								__('Showing %1$s of %2$s — type to search.'),
								String(browseShown),
								String(browseTotal),
							),
						)
					: null,
				resultButtons.length === 0 && !waiting
					? api.element.createElement(
							'p',
							{ className: 'components-base-control__help' },
							trimmed === '' ? __('No terms found.') : __('No terms match your search.'),
						)
					: null,
				status !== ''
					? api.element.createElement('p', { className: 'components-base-control__help' }, status)
					: null,
			)
		: null;

	return api.element.createElement(
		'div',
		{
			key: field.key,
			className: 'kk-termsearch-field' + (open ? ' is-open' : ''),
			ref: fieldRef,
			onFocus: (event: FocusEvent): void => {
				if (isSearchInput(event)) {
					setOpen(true);
				}
			},
			onClick: (event: MouseEvent): void => {
				if (isSearchInput(event)) {
					setOpen(true);
				}
			},
			onBlur: closeOnLeave,
			onKeyDown: closeOnEscape,
		},
		api.element.createElement('p', { className: 'kk-termsearch-label' }, field.label),
		api.element.createElement('ul', { className: 'kk-termsearch-list' }, ...chips),
		api.element.createElement(
			'div',
			{ className: 'kk-termsearch-control' },
			api.element.createElement(api.components.TextControl, {
				label: __('Search terms'),
				value: query,
				onChange: (next: string) => onQueryChange(next),
			}),
			dropdown,
		),
		field.help !== undefined && field.help !== ''
			? api.element.createElement('p', { className: 'components-base-control__help' }, field.help)
			: null,
	);
}

function PostSearchField(props: {
	field: { key: string; label: string; help?: string };
	value: unknown;
	set: (key: string, value: unknown) => void;
	api: Wp;
}): unknown {
	const { field, value, set, api } = props;
	const __ = (text: string): string => api.i18n.__(text, 'koperasikita');
	const id = typeof value === 'number' ? value : 0;
	/* Post ids and page ids never collide (one wp_posts table), so
	 * both lookups can run in parallel; at most one resolves. */
	const postRecord = api.data.useSelect(
		(select) => (id > 0 ? select('core').getEntityRecord('postType', 'post', id) : null),
		[id],
	);
	const pageRecord = api.data.useSelect(
		(select) => (id > 0 ? select('core').getEntityRecord('postType', 'page', id) : null),
		[id],
	);
	const [query, setQuery] = api.element.useState('');
	const [results, setResults] = api.element.useState<LinkSearchResult[]>([]);
	const [searching, setSearching] = api.element.useState(false);
	const [status, setStatus] = api.element.useState('');
	const timer = api.element.useRef<number | null>(null);

	const search = (nextQuery: string): void => {
		if (timer.current !== null) {
			window.clearTimeout(timer.current);
			timer.current = null;
		}
		if (nextQuery.trim().length < 2) {
			setResults([]);
			setStatus('');
			return;
		}
		setStatus(__('Searching…'));
		timer.current = window.setTimeout(() => {
			const ajaxUrl = kkWidgetBlocks?.ajaxUrl ?? '';
			const nonce = kkWidgetBlocks?.nonce ?? '';
			if (ajaxUrl === '' || nonce === '') {
				setSearching(false);
				setStatus(__('Search is unavailable right now.'));
				return;
			}
			setSearching(true);
			const body = new URLSearchParams({
				action: 'kk_agenda_link_search',
				nonce,
				query: nextQuery.trim(),
			});
			void fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body })
				.then((response) => response.json() as Promise<unknown>)
				.then((json) => {
					setSearching(false);
					const data = json as {
						success?: boolean;
						data?: { results?: unknown };
					};
					const raw = Array.isArray(data.data?.results) ? data.data.results : [];
					const found = raw.filter(isLinkSearchResult);
					setResults(found);
					setStatus(found.length === 0 ? __('No results.') : '');
				})
				.catch(() => {
					setSearching(false);
					setStatus(__('Search is unavailable right now.'));
				});
		}, 300);
	};

	const pick = (result: LinkSearchResult): void => {
		set(field.key, result.id);
		setResults([]);
		setQuery('');
		setStatus(`${result.title} ${__('selected.')}`);
	};

	const renderedTitle =
		postRecord?.title?.rendered ?? pageRecord?.title?.rendered ?? '';
	const title = renderedTitle !== '' ? decodeTitle(renderedTitle) : '';
	const resolving =
		id > 0 && title === '' && (postRecord === undefined || pageRecord === undefined);
	const missing = id > 0 && postRecord === null && pageRecord === null;

	const resultButtons = results.map((result) =>
		api.element.createElement(
			'li',
			{ key: `${result.type}-${result.id}` },
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-postsearch-result',
					onClick: () => pick(result),
				},
				`${result.title} (${result.type})`,
			),
		),
	);

	return api.element.createElement(
		'div',
		{ key: field.key, className: 'kk-postsearch-field' },
		api.element.createElement('p', { className: 'kk-postsearch-label' }, field.label),
		id > 0
			? api.element.createElement(
					'div',
					{ className: 'kk-postsearch-chosen' },
					api.element.createElement(
						'span',
						{ className: 'kk-postsearch-name' },
						resolving ? __('Loading…') : missing ? __('Post not found.') : title,
					),
					api.element.createElement(
						'button',
						{
							type: 'button',
							className: 'kk-postsearch-remove',
							onClick: () => {
								set(field.key, 0);
								setStatus('');
							},
						},
						__('Remove link'),
					),
				)
			: null,
		api.element.createElement(api.components.TextControl, {
			label: __('Search posts and pages by title…'),
			help: field.help ?? '',
			value: query,
			onChange: (next: string) => {
				setQuery(next);
				search(next);
			},
		}),
		searching ? api.element.createElement(api.components.Spinner, null) : null,
		resultButtons.length > 0
			? api.element.createElement('ul', { className: 'kk-postsearch-results' }, ...resultButtons)
			: null,
		status !== ''
			? api.element.createElement('p', { className: 'components-base-control__help' }, status)
			: null,
	);
}

/* The Data Desk widget overrides: symbol, days and one
 * show/hide toggle per stat, on top of the picked template.
 * The controls show the template's values while no override
 * is stored (an empty symbol / 0 days means "use the
 * template's"); a stat the template hides shows its toggle
 * off and disabled - the template hides it, the widget
 * cannot show it back. A real component (not a renderField
 * branch) because the labels and defaults come from the
 * picked template, not from the field definition. */
function DataDeskOverridesField(props: {
	field: { key: string; label: string };
	attributes: Record<string, unknown>;
	set: (key: string, value: unknown) => void;
	api: Wp;
}): unknown {
	const { attributes, set, api } = props;
	const __ = (text: string): string => api.i18n.__(text, 'koperasikita');
	const meta = kkWidgetBlocks?.dataDeskTemplateMeta ?? {};
	const templateId =
		typeof attributes.template === 'string' && attributes.template !== '' && meta[attributes.template] !== undefined
			? (attributes.template as string)
			: (Object.keys(meta)[0] ?? '');
	const templateMeta = meta[templateId];
	const symbolLabel = templateMeta
		? (kkWidgetBlocks?.dataDeskSymbols ?? []).find((option) => option.value === templateMeta.symbol)?.label ?? templateMeta.symbol
		: '';
	const symbolOverride = typeof attributes.symbolOverride === 'string' ? attributes.symbolOverride : '';
	const daysOverride = typeof attributes.daysOverride === 'number' ? attributes.daysOverride : 0;
	const hideKey = (block: string): string => `hide${block[0].toUpperCase()}${block.slice(1)}`;
	const toggles = ['sell', 'buyback', 'performance'].map((block) => {
		const hiddenByTemplate = templateMeta?.hiddenBlocks.includes(block) ?? false;
		const hide = attributes[hideKey(block)] === true;
		return api.element.createElement(api.components.ToggleControl, {
			key: block,
			label: templateMeta?.statLabels[block] ?? block,
			help: hiddenByTemplate ? __('Hidden by the template.') : '',
			checked: !hiddenByTemplate && !hide,
			disabled: hiddenByTemplate,
			onChange: (next: boolean) => set(hideKey(block), !next),
		});
	});
	return api.element.createElement(
		'div',
		{ key: props.field.key },
		api.element.createElement(api.components.SelectControl, {
			label: __('Symbol'),
			value: symbolOverride,
			options: [
				{ value: '', label: api.i18n.sprintf(__('Use template (%s)'), symbolLabel) },
				...(kkWidgetBlocks?.dataDeskSymbols ?? []),
			],
			onChange: (next: string) => set('symbolOverride', next),
		}),
		api.element.createElement(api.components.TextControl, {
			label: __('Days'),
			help: __('Empty uses the template days.'),
			type: 'number',
			value: daysOverride > 0 ? String(daysOverride) : '',
			placeholder: templateMeta ? String(templateMeta.days) : '',
			min: 3,
			max: 30,
			onChange: (next: string) => {
				const parsed = parseInt(next, 10);
				set('daysOverride', Number.isNaN(parsed) ? 0 : Math.max(3, Math.min(30, parsed)));
			},
		}),
		api.element.createElement('p', null, __('Show or hide each stat for this widget only. The template is unchanged.')),
		...toggles,
	);
}

function renderField(
	field: FieldDef,
	attributes: Record<string, unknown>,
	set: (key: string, value: unknown) => void,
	api: Wp,
): unknown {
	const value = attributes[field.key];
	const key = field.key;

	switch (field.kind) {
		case 'text':
			return api.element.createElement(api.components.TextControl, {
				key,
				label: field.label,
				help: field.help ?? '',
				value: typeof value === 'string' ? value : '',
				onChange: (next: string) => set(key, next),
			});
		case 'textarea':
			return api.element.createElement(api.components.TextareaControl, {
				key,
				label: field.label,
				help: field.help ?? '',
				value: typeof value === 'string' ? value : '',
				onChange: (next: string) => set(key, next),
			});
		case 'number':
			return api.element.createElement(api.components.RangeControl, {
				key,
				label: field.label,
				help: field.help ?? '',
				value: typeof value === 'number' ? value : (field.fallback ?? 5),
				min: field.min,
				max: field.max,
				onChange: (next: number | undefined) => set(key, next ?? (field.fallback ?? 5)),
			});
		case 'select': {
			/* A select that only means something while another
			 * attribute holds a value (the header style needs a
			 * title) is disabled with a hint while that attribute
			 * is empty, instead of sitting there pretending to
			 * work. */
			const gated = field.disabledWhenEmpty?.key;
			const gatedValue = gated === undefined ? undefined : attributes[gated];
			const disabled =
				gated !== undefined &&
				(typeof gatedValue !== 'string' || gatedValue.trim() === '');
			return api.element.createElement(api.components.SelectControl, {
				key,
				label: field.label,
				help: disabled ? (field.disabledHelp ?? '') : '',
				disabled,
				value: typeof value === 'string' ? value : '',
				options: field.options,
				onChange: (next: string) => set(key, next),
			});
		}
		case 'toggle':
			return api.element.createElement(api.components.ToggleControl, {
				key,
				label: field.label,
				checked: value === true,
				onChange: (next: boolean) => set(key, next),
			});
		case 'checkboxes': {
			const selected: string[] = Array.isArray(value)
				? value.filter((item): item is string => typeof item === 'string')
				: [];
			const boxes = field.options.map((option) =>
				api.element.createElement(api.components.CheckboxControl, {
					key: option.value,
					label: option.label,
					checked: selected.includes(option.value),
					onChange: (next: boolean) => {
						const nextSelected = next
							? [...selected, option.value]
							: selected.filter((item) => item !== option.value);
						set(key, nextSelected);
					},
				}),
			);
			return api.element.createElement(
				'div',
				{ key },
				api.element.createElement('p', null, field.label),
				...boxes,
			);
		}
		case 'termsearch': {
			/* Searchable term picker: picked terms as chips plus a
			 * search box - the core store for categories, the
			 * localized list for brands. A real component, so it
			 * can hold the search state. */
			return api.element.createElement(TermSearchField, { key, field, value, set, api });
		}
		case 'locations': {
			/* The weather locations list: saved places with remove
			 * buttons, plus a geocoding search to add more. A real
			 * component - it holds the search state. */
			return api.element.createElement(LocationsField, { key, field, value, set, api });
		}
		case 'postsearch': {
			/* The agenda "Link to" search: the chosen post or page
			 * plus a title search. A real component - it holds the
			 * search state and resolves the stored id to a title. */
			return api.element.createElement(PostSearchField, { key, field, value, set, api });
		}
		case 'data-desk-overrides': {
			return api.element.createElement(DataDeskOverridesField, { key, field, attributes, set, api });
		}
		case 'color': {
			/* A native colour swatch + hex readout + clear button.
			 * The input always holds a colour (the dialog needs a
			 * starting value), so the stored value is what counts:
			 * empty means "fall back to the theme colour", and the
			 * x button restores it. */
			const hex = typeof value === 'string' ? value : '';
			return api.element.createElement(
				'div',
				{ key },
				api.element.createElement('p', null, field.label),
				api.element.createElement('input', {
					type: 'color',
					value: hex !== '' ? hex : (field.fallback ?? '#ffffff'),
					onChange: (event: { target: { value: string } }) => set(key, event.target.value),
				}),
				api.element.createElement('span', null, hex),
				hex !== ''
					? api.element.createElement(
							'button',
							{ type: 'button', onClick: () => set(key, '') },
							'x',
						)
					: null,
				field.help !== undefined
					? api.element.createElement('p', { className: 'components-base-control__help' }, field.help)
					: null,
			);
		}
	}
}

function createEdit(def: BlockDef, api: Wp): (props: BlockEditProps) => unknown {
	return (props: BlockEditProps) => {
		const { attributes, setAttributes } = props;
		/* The canvas class stands in for `.kk-sidebar` in the editor:
		 * the_widget() renders `<div class="widget">` + `<h2
		 * class="widgettitle">` (not the frontend's kk-widget /
		 * kk-widget-title), so editor.css scopes the sidebar shell
		 * rule and the title rule to this wrapper. */
		const blockProps = api.blockEditor.useBlockProps({ className: 'kk-widget-block-canvas' });
		const set = (key: string, next: unknown): void => {
			setAttributes({ [key]: next });
		};
		/* Fields with a showWhen only render while the named
		 * attribute holds the given value (the Choose Own colours
		 * only show for the custom card style). */
		const controls = def.fields
			.filter(
				(field) =>
					!('showWhen' in field) ||
					field.showWhen === undefined ||
					attributes[field.showWhen.key] === field.showWhen.value,
			)
			.map((field) => renderField(field, attributes, set, api));
		const inspector = api.element.createElement(
			api.blockEditor.InspectorControls,
			null,
			api.element.createElement(
				api.components.PanelBody,
				{ title: api.i18n.__('Settings', 'koperasikita'), initialOpen: true },
				...controls,
			),
		);
		/* Live preview: ServerSideRender asks the server for the
		 * block's real frontend output (the widget rendered through
		 * the_widget()), so the editor shows exactly what the site
		 * will show - not a title/description placeholder. */
		const preview = api.element.createElement(
			'div',
			blockProps,
			api.serverSideRender !== undefined
				? api.element.createElement(api.serverSideRender.ServerSideRender, {
						block: `koperasi-kita/${def.slug}`,
						attributes,
						/* POST, not the default GET: over GET the
						 * attributes travel in the query string, so a
						 * boolean false arrives as the text "false"
						 * and still renders as on. */
						httpMethod: 'POST',
					})
				: api.element.createElement(
						'div',
						{ className: 'kk-widget-block-preview' },
						api.element.createElement('strong', null, def.title),
						api.element.createElement('p', null, def.description),
						api.element.createElement(
							'p',
							null,
							api.i18n.__('Configure this widget in the block settings.', 'koperasikita'),
						),
					),
		);
		return api.element.createElement(api.element.Fragment, null, inspector, preview);
	};
}

function buildDefinitions(api: Wp): BlockDef[] {
	const __ = (text: string): string => api.i18n.__(text, 'koperasikita');
	const storyAttributes: Record<string, Record<string, unknown>> = {
		title: { type: 'string', default: '' },
		headerStyle: { type: 'string', default: 'classic-top' },
		count: { type: 'number', default: 5 },
		layout: { type: 'string', default: 'list' },
		showThumbnail: { type: 'boolean', default: true },
		marginTop: { type: 'number', default: 0 },
		marginBottom: { type: 'number', default: 10 },
	};
	/* The header style field, shared by every block with a title.
	 * Only the data-desk block gates its copy on the title (see
	 * below): its widget renders no header without one. The other
	 * blocks keep today's behaviour - their title fallbacks are
	 * their own business. */
	const headerStyleField: Extract<FieldDef, { kind: 'select' }> = {
		key: 'headerStyle',
		kind: 'select',
		label: __('Header Style'),
		options: [
			{ value: 'classic-top', label: __('Classic Top Border') },
			{ value: 'classic-top-bottom', label: __('Classic Top Bottom Border') },
			{ value: 'classic-bottom', label: __('Classic Bottom Border') },
			{ value: 'no-border', label: __('Borderless') },
			{ value: 'hide', label: __('Hide') },
		],
	};
	const marginTopField: FieldDef = {
		key: 'marginTop',
		kind: 'number',
		label: __('Margin top (px)'),
		min: 0,
		max: 100,
		fallback: 0,
	};
	const marginBottomField: FieldDef = {
		key: 'marginBottom',
		kind: 'number',
		label: __('Margin bottom (px)'),
		min: 0,
		max: 100,
		fallback: 0,
	};
	const storyMarginBottomField: FieldDef = {
		key: 'marginBottom',
		kind: 'number',
		label: __('Margin bottom (px)'),
		min: 0,
		max: 100,
		fallback: 10,
	};
	const storyFields: FieldDef[] = [
		{ key: 'title', kind: 'text', label: __('Title') },
		headerStyleField,
		marginTopField,
		storyMarginBottomField,
		{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
		{
			key: 'layout',
			kind: 'select',
			label: __('Layout'),
			options: [
				{ value: 'list', label: __('List') },
				{ value: 'grid', label: __('Grid') },
			],
		},
		{ key: 'showThumbnail', kind: 'toggle', label: __('Show thumbnails') },
	];

	const definitions: BlockDef[] = [
		{
			slug: 'recent-news',
			title: __('KK Recent News'),
			description: __('The latest published posts, newest first.'),
			icon: 'megaphone',
			attributes: storyAttributes,
			fields: storyFields,
		},
		{
			slug: 'popular-news',
			title: __('KK Popular News'),
			description: __('Most discussed posts, ranked by comment count.'),
			icon: 'chart-line',
			attributes: storyAttributes,
			fields: storyFields,
		},
		{
			slug: 'related-news',
			title: __('KK Related News'),
			description: __('Posts related to the one being read, or the latest posts elsewhere.'),
			icon: 'networking',
			attributes: storyAttributes,
			fields: storyFields,
		},
		{
			slug: 'editors-picks',
			title: __('KK Post By Terms'),
			description: __('Posts from the categories and tags you pick, in your chosen order.'),
			icon: 'star-filled',
			attributes: {
				...storyAttributes,
				categoryIds: { type: 'array', default: [], items: { type: 'string' } },
				tagIds: { type: 'array', default: [], items: { type: 'string' } },
				order: { type: 'string', default: 'date_desc' },
			},
			fields: [
				...storyFields,
				{
					key: 'categoryIds',
					kind: 'termsearch',
					label: __('Categories'),
					help: __('Leave empty to show posts from every category.'),
					source: 'categories',
					options: kkWidgetBlocks?.categories ?? [],
				},
				{
					key: 'tagIds',
					kind: 'termsearch',
					label: __('Tags'),
					help: __('Leave empty to show posts from every tag.'),
					source: 'tags',
					options: kkWidgetBlocks?.tags ?? [],
				},
				{
					key: 'order',
					kind: 'select',
					label: __('Order'),
					options: [
						{ value: 'date_desc', label: __('Newest first') },
						{ value: 'date_asc', label: __('Oldest first') },
					],
				},
			],
		},
		{
			slug: 'pages',
			title: __('KK Pages'),
			description: __('A list of page links, in menu order, by date or by title.'),
			icon: 'page',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				count: { type: 'number', default: 5 },
				sortOrder: { type: 'string', default: 'menu_order' },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
				{
					key: 'sortOrder',
					kind: 'select',
					label: __('Sort order'),
					options: [
						{ value: 'menu_order', label: __('Menu order') },
						{ value: 'date', label: __('Date') },
						{ value: 'title', label: __('Title') },
					],
				},
			],
		},
		{
			slug: 'newsletter',
			title: __('KK Newsletter'),
			description: __('The newsletter subscribe form, for any widget area.'),
			icon: 'mail',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				text: { type: 'string', default: __('News, announcements and market updates from the cooperative, delivered to your inbox.') },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{ key: 'text', kind: 'textarea', label: __('Text') },
			],
		},
		{
			slug: 'quote',
			title: __('KK Quote'),
			description: __('A single quote card, for any widget area.'),
			icon: 'message-square',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				quote: { type: 'string', default: '' },
				author: { type: 'string', default: '' },
				role: { type: 'string', default: '' },
				linkUrl: { type: 'string', default: '' },
				linkLabel: { type: 'string', default: '' },
				marginTop: { type: 'number', default: 0 },
				marginBottom: { type: 'number', default: 0 },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{ key: 'quote', kind: 'textarea', label: __('Quote') },
				{ key: 'author', kind: 'text', label: __('Author') },
				{ key: 'role', kind: 'text', label: __('Role') },
				{ key: 'linkUrl', kind: 'text', label: __('Link URL'), help: __('Leave empty to hide the link.') },
				{ key: 'linkLabel', kind: 'text', label: __('Link label'), help: __('Leave empty for the default label.') },
			],
		},
		{
			slug: 'category-list-content',
			title: __('KK Category List Content'),
			description: __('Posts from one category, in a Featured, Grid, List, Featured Right or List Column layout.'),
			icon: 'category',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				termId: { type: 'string', default: '' },
				layout: { type: 'string', default: 'featured' },
				linkTo: { type: 'boolean', default: true },
				count: { type: 'number', default: 5 },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title'), help: __('Leave empty to use the category name.') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'termId',
					kind: 'termsearch',
					label: __('Category'),
					help: __('Leave empty to use the category name.'),
					single: true,
					source: 'categories',
					options: kkWidgetBlocks?.categories ?? [],
				},
				{
					key: 'layout',
					kind: 'select',
					label: __('Layout'),
					options: [
						{ value: 'featured', label: __('Featured') },
						{ value: 'grid', label: __('Grid') },
						{ value: 'list', label: __('List') },
						{ value: 'featured-right', label: __('Featured Right') },
						{ value: 'list-column', label: __('List Column') },
					],
				},
				{ key: 'linkTo', kind: 'toggle', label: __('Enable Link To') },
				{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
			],
		},
		{
			slug: 'tag-list-content',
			title: __('KK Tag List Content'),
			description: __('Posts from one tag, in a Featured, Grid, List, Featured Right or List Column layout.'),
			icon: 'tag',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				termId: { type: 'string', default: '' },
				layout: { type: 'string', default: 'featured' },
				linkTo: { type: 'boolean', default: true },
				count: { type: 'number', default: 5 },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title'), help: __('Leave empty to use the tag name.') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'termId',
					kind: 'termsearch',
					label: __('Tag'),
					help: __('Leave empty to use the tag name.'),
					single: true,
					source: 'tags',
					options: kkWidgetBlocks?.tags ?? [],
				},
				{
					key: 'layout',
					kind: 'select',
					label: __('Layout'),
					options: [
						{ value: 'featured', label: __('Featured') },
						{ value: 'grid', label: __('Grid') },
						{ value: 'list', label: __('List') },
						{ value: 'featured-right', label: __('Featured Right') },
						{ value: 'list-column', label: __('List Column') },
					],
				},
				{ key: 'linkTo', kind: 'toggle', label: __('Enable Link To') },
				{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
			],
		},
		{
			slug: 'category-summary',
			title: __('KK Category Summary'),
			description: __('The latest post from each picked category, side by side in columns.'),
			icon: 'columns',
			attributes: {
				title: { type: 'string', default: '' },
				categoryIds: { type: 'array', default: [], items: { type: 'string' } },
				count: { type: 'number', default: 1 },
				showExcerpt: { type: 'boolean', default: true },
				showTime: { type: 'boolean', default: true },
				indexLabel: { type: 'string', default: '' },
				indexUrl: { type: 'string', default: '' },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				marginTopField,
				marginBottomField,
				{
					key: 'categoryIds',
					kind: 'termsearch',
					label: __('Categories to show'),
					help: __('Leave empty to show the three categories with the most posts.'),
					source: 'categories',
					options: kkWidgetBlocks?.categories ?? [],
				},
				{ key: 'count', kind: 'number', label: __('Posts per category'), min: 1, max: 5 },
				{ key: 'showExcerpt', kind: 'toggle', label: __('Show excerpt') },
				{ key: 'showTime', kind: 'toggle', label: __('Show time') },
				{ key: 'indexLabel', kind: 'text', label: __('Index link label'), help: __('Leave empty for the default label.') },
				{ key: 'indexUrl', kind: 'text', label: __('Index link URL'), help: __('Leave empty to hide the index link.') },
			],
		},
		{
			slug: 'gold',
			title: __('KK Gold Prices'),
			description: __('Gold prices for Indonesian gold brands in an auto-scrolling slider.'),
			icon: 'star-filled',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				brands: { type: 'array', default: [], items: { type: 'string' } },
				style: { type: 'string', default: 'vertical-carousel' },
				colorStyle: { type: 'string', default: 'auto' },
				autoplay: { type: 'boolean', default: true },
				interval: { type: 'number', default: 5 },
				itemsPerView: { type: 'number', default: 1 },
				showDots: { type: 'boolean', default: false },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'brands',
					kind: 'termsearch',
					label: __('Brands to show'),
					help: __('Leave empty to show all brands.'),
					source: 'options',
					options: kkWidgetBlocks?.goldBrands ?? [],
				},
				{
					key: 'style',
					kind: 'select',
					label: __('Slider style'),
					options: [
						{ value: 'vertical-carousel', label: __('Vertical Carousel') },
						{ value: 'horizontal-carousel', label: __('Horizontal Carousel') },
						{ value: 'slider-horizontal', label: __('Slider Horizontal') },
					],
				},
				{
					key: 'itemsPerView',
					kind: 'select',
					label: __('Items per view'),
					options: [
						{ value: '1', label: __('1 item') },
						{ value: '2', label: __('2 items') },
						{ value: '3', label: __('3 items') },
					],
				},
				{
					key: 'colorStyle',
					kind: 'select',
					label: __('Colour style'),
					options: [
						{ value: 'auto', label: __('Auto') },
						{ value: 'dark', label: __('Dark Green') },
						{ value: 'light', label: __('Light') },
					],
				},
				{ key: 'autoplay', kind: 'toggle', label: __('Autoplay') },
				{ key: 'showDots', kind: 'toggle', label: __('Show dots') },
				{ key: 'interval', kind: 'number', label: __('Autoplay interval (seconds)'), min: 2, max: 30 },
			],
		},
		{
			slug: 'data-desk',
			title: __('KK Data Desk'),
			description: __('One market symbol as a data desk panel: price chart and stats. Pick a template.'),
			icon: 'chart-bar',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				template: { type: 'string', default: '' },
				symbolOverride: { type: 'string', default: '' },
				daysOverride: { type: 'number', default: 0 },
				hideSell: { type: 'boolean', default: false },
				hideBuyback: { type: 'boolean', default: false },
				hidePerformance: { type: 'boolean', default: false },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				{
					...headerStyleField,
					/* With no title there is no header to style:
					 * WidgetHeader renders nothing, so every style
					 * looks identical. The field stays visible but
					 * disabled, saying why, instead of letting the
					 * choice pretend to do something. */
					disabledWhenEmpty: { key: 'title' },
					disabledHelp: __('Set a title first - the header style only applies to the widget title.'),
				},
				marginTopField,
				marginBottomField,
				{
					key: 'template',
					kind: 'select',
					label: __('Template'),
					options: kkWidgetBlocks?.dataDeskTemplates ?? [],
				},
				{
					key: 'overrides',
					kind: 'data-desk-overrides',
					label: __('Overrides'),
				},
			],
		},
		{
			slug: 'markets',
			title: __('KK Markets'),
			description: __('Gold, stock, forex, commodity and crypto prices from the Markets providers.'),
			icon: 'chart-line',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				markets: { type: 'array', default: [], items: { type: 'string' } },
				items: { type: 'array', default: [], items: { type: 'string' } },
				showChange: { type: 'boolean', default: true },
				layout: { type: 'string', default: 'list' },
				visible: { type: 'number', default: 1 },
				style: { type: 'string', default: 'default' },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'style',
					kind: 'select',
					label: __('Style'),
					options: [
						{ value: 'default', label: __('Default') },
						{ value: 'no-margin', label: __('No Margin') },
					],
				},
				{
					key: 'layout',
					kind: 'select',
					label: __('Layout'),
					options: [
						{ value: 'list', label: __('Vertical List') },
						{ value: 'grid', label: __('Grid') },
						{ value: 'slider-horizontal', label: __('Horizontal Slider') },
						{ value: 'slider-vertical', label: __('Vertical Slider') },
						{ value: 'strip', label: __('Full-width Strip') },
						{ value: 'marquee', label: __('Marquee') },
						{ value: 'index-strip', label: __('Index Strip') },
					],
				},
				{
					key: 'visible',
					kind: 'number',
					label: __('Visible items (desktop)'),
					help: __('How many items show at once in the Horizontal Slider and Marquee layouts.'),
					min: 1,
					max: 4,
				},
				{
					key: 'markets',
					kind: 'checkboxes',
					label: __('Markets to show'),
					options: kkWidgetBlocks?.marketLabels ?? [],
				},
				{
					key: 'items',
					kind: 'termsearch',
					label: __('Items to show'),
					help: __('Leave empty to show all items of the markets above. Pick up to 16 items.'),
					source: 'options',
					options: kkWidgetBlocks?.marketItems ?? [],
				},
				{ key: 'showChange', kind: 'toggle', label: __('Show day change') },
			],
		},
		{
			slug: 'yahoo-finance',
			title: __('KK Yahoo Finance'),
			description: __('Live IHSG index from Yahoo Finance (free, no API key).'),
			icon: 'chart-line',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				showChange: { type: 'boolean', default: true },
				layout: { type: 'string', default: 'list' },
				visible: { type: 'number', default: 1 },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'layout',
					kind: 'select',
					label: __('Layout'),
					options: [
						{ value: 'list', label: __('Vertical List') },
						{ value: 'grid', label: __('Grid') },
						{ value: 'slider-horizontal', label: __('Horizontal Slider') },
						{ value: 'slider-vertical', label: __('Vertical Slider') },
						{ value: 'strip', label: __('Full-width Strip') },
						{ value: 'marquee', label: __('Marquee') },
					],
				},
				{
					key: 'visible',
					kind: 'number',
					label: __('Visible items (desktop)'),
					help: __('How many items show at once in the Horizontal Slider and Marquee layouts.'),
					min: 1,
					max: 4,
				},
				{ key: 'showChange', kind: 'toggle', label: __('Show day change') },
			],
		},
		{
			slug: 'weather',
			title: __('KK: Cuaca'),
			description: __('Current weather for a city, with a location picker.'),
			icon: 'cloud',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				defaultCity: { type: 'string', default: 'Padang' },
				showDetails: { type: 'boolean', default: false },
				locations: { type: 'array', default: [] },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				marginTopField,
				marginBottomField,
				{
					key: 'locations',
					kind: 'locations',
					label: __('Locations'),
					help: __('Search for a city to add it. The first location is the default; the rest appear as quick picks in the location picker.'),
				},
				{
					key: 'showDetails',
					kind: 'toggle',
					label: __('Show details (feels like, high/low, humidity, wind)'),
				},
			],
		},
		{
			slug: 'agenda',
			title: __('KK Agenda'),
			description: __('Upcoming cooperative events as a card that follows the site colour scheme; live events get a green dot, finished events are dimmed.'),
			icon: 'calendar',
			attributes: {
				title: { type: 'string', default: __('Agenda Koperasi') },
				count: { type: 'number', default: 5 },
				showPast: { type: 'boolean', default: true },
				showIcon: { type: 'boolean', default: true },
				icon: { type: 'string', default: 'users' },
				style: { type: 'string', default: 'auto' },
				bgColor: { type: 'string', default: '' },
				accentColor: { type: 'string', default: '' },
				linkTo: { type: 'number', default: 0 },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				marginTopField,
				marginBottomField,
				{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
				{
					key: 'showPast',
					kind: 'toggle',
					label: __('Show past events'),
				},
				{ key: 'showIcon', kind: 'toggle', label: __('Show icon') },
				{
					key: 'icon',
					kind: 'select',
					label: __('Icon'),
					options: kkWidgetBlocks?.icons ?? [],
					showWhen: { key: 'showIcon', value: true },
				},
				{
					key: 'style',
					kind: 'select',
					label: __('Card style'),
					options: [
						{ value: 'auto', label: __('Auto') },
						{ value: 'dark', label: __('Dark Green') },
						{ value: 'light', label: __('Light') },
						{ value: 'custom', label: __('Choose Own') },
					],
				},
				{
					key: 'bgColor',
					kind: 'color',
					label: __('Background'),
					showWhen: { key: 'style', value: 'custom' },
					fallback: '#ffffff',
				},
				{
					key: 'accentColor',
					kind: 'color',
					label: __('Accent text'),
					showWhen: { key: 'style', value: 'custom' },
					fallback: '#0b6b3a',
				},
				{
					key: 'linkTo',
					kind: 'postsearch',
					label: __('Link to'),
					help: __('The card title links to this post or page.'),
				},
			],
		},
	];

	// Every block carries the two margin attributes: the story
	// blocks already declare theirs (bottom defaults to 10px); the
	// rest get the 0 / 0 pair here, so a block added later cannot
	// forget them.
	for (const def of definitions) {
		if (def.attributes.marginTop === undefined) {
			def.attributes.marginTop = { type: 'number', default: 0 };
			def.attributes.marginBottom = { type: 'number', default: 0 };
		}
	}

	return definitions;
}

if (typeof wp !== 'undefined') {
	// Only the blocks the server registered (the feature-gated ones are
	// left out when their feature is off); without the localized list,
	// register them all.
	const enabled = kkWidgetBlocks?.blocks;
	buildDefinitions(wp).forEach((def) => {
		if (enabled !== undefined && !enabled.includes(def.slug)) {
			return;
		}
		/* Inserter preview: without an `example` the inserter shows
		 * "No Preview Available". The example is the attribute
		 * defaults, so the preview is the block as first inserted -
		 * the ServerSideRender preview, not a placeholder. */
		const exampleAttributes: Record<string, unknown> = {};
		for (const [key, schema] of Object.entries(def.attributes)) {
			if (schema !== null && typeof schema === 'object' && 'default' in schema) {
				exampleAttributes[key] = (schema as { default?: unknown }).default;
			}
		}
		wp.blocks.registerBlockType(`koperasi-kita/${def.slug}`, {
			title: def.title,
			description: def.description,
			icon: def.icon,
			category: 'koperasi-kita',
			attributes: def.attributes,
			example: { attributes: exampleAttributes },
			edit: createEdit(def, wp),
			save: () => null,
		});
	});
}
