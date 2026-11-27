/**
 * Koperasi Kita — widget blocks editor script.
 *
 * Registers the theme widgets as blocks in the "Koperasi Kita" inserter
 * category (the block types themselves, with render callbacks, are
 * registered server-side in inc/Widgets/Blocks.php). Each block gets an
 * edit UI built from a small field schema: the InspectorControls show
 * the widget's own fields (title, count, layout, thumbnails, and the
 * per-widget extras), while the canvas shows a live ServerSideRender
 * preview — the real frontend markup, rendered on the server through
 * the_widget().
 *
 * The category/tag pickers are Select2-style token fields
 * (FormTokenField): searchable, with one chip per picked term. The
 * term lists come from the core store (wp.data), so they always
 * match the site's categories and tags without a page reload.
 */

export {};

interface OptionItem {
	value: string;
	label: string;
}

type FieldDef =
	| { key: string; kind: 'text'; label: string; help?: string }
	| { key: string; kind: 'textarea'; label: string; help?: string }
	| { key: string; kind: 'number'; label: string; help?: string; min: number; max: number }
	| { key: string; kind: 'select'; label: string; options: OptionItem[] }
	| { key: string; kind: 'toggle'; label: string }
	| { key: string; kind: 'checkboxes'; label: string; options: OptionItem[] }
	| { key: string; kind: 'multiselect'; label: string; help?: string; options: OptionItem[] }
	| { key: string; kind: 'categories'; label: string; help?: string }
	| { key: string; kind: 'tags'; label: string; help?: string }
	| { key: string; kind: 'locations'; label: string; help?: string };

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

interface WpDataSelect {
	(store: string): {
		getEntityRecords: (
			kind: string,
			name: string,
			query?: Record<string, unknown>,
		) => CategoryRecord[] | null;
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
	};
}

declare const wp: Wp | undefined;

declare const kkWidgetBlocks:
	| {
			blocks?: string[];
			goldBrands?: OptionItem[];
			marketLabels?: OptionItem[];
			categories?: OptionItem[];
			tags?: OptionItem[];
			dataDeskTemplates?: OptionItem[];
			dataDeskTableTemplates?: OptionItem[];
			ajaxUrl?: string;
			nonce?: string;
	  }
	| undefined;

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
 * through the theme's own kk_weather_search endpoint — the same
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
				`${location.name} — ${location.latitude}, ${location.longitude}`,
			),
			api.element.createElement(
				'button',
				{
					type: 'button',
					className: 'kk-locations-remove',
					'aria-label': `${__('Remove')} ${location.name}`,
					onClick: () => set(field.key, locations.filter((_, i) => i !== index)),
				},
				'×',
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

function renderField(
	field: FieldDef,
	attributes: Record<string, unknown>,
	set: (key: string, value: unknown) => void,
	categories: CategoryRecord[] | null,
	tags: CategoryRecord[] | null,
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
				value: typeof value === 'number' ? value : 5,
				min: field.min,
				max: field.max,
				onChange: (next: number | undefined) => set(key, next ?? 5),
			});
		case 'select':
			return api.element.createElement(api.components.SelectControl, {
				key,
				label: field.label,
				value: typeof value === 'string' ? value : '',
				options: field.options,
				onChange: (next: string) => set(key, next),
			});
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
		case 'multiselect': {
			/* A real multi-select dropdown (WordPress SelectControl
			 * with multiple): all options visible at once, proper
			 * value/label mapping, stored as an array of slugs.
			 * An empty selection means "all" — same as the checkbox
			 * list it replaces. */
			const selected: string[] = Array.isArray(value)
				? value.filter((item): item is string => typeof item === 'string')
				: [];
			return api.element.createElement(api.components.SelectControl, {
				key,
				label: field.label,
				help: field.help ?? '',
				multiple: true,
				value: selected,
				options: field.options,
				onChange: (next: string[] | string) => {
					set(key, Array.isArray(next) ? next : [next]);
				},
			});
		}
		case 'categories': {
			/* Checkbox list, not a token field: every category shows
			 * directly as a checkbox, so the editor sees the options
			 * the moment the panel opens — no typing, no focusing to
			 * reveal them (FormTokenField only suggested matches for
			 * typed text, so an empty focus showed nothing). The
			 * stored value is term IDs. */
			if (categories === null) {
				return api.element.createElement(api.components.Spinner, { key });
			}
			const selected: number[] = Array.isArray(value)
				? value.filter((item): item is number => typeof item === 'number')
				: [];
			const boxes = categories.map((c) =>
				api.element.createElement(api.components.CheckboxControl, {
					key: c.id,
					label: c.name,
					checked: selected.includes(c.id),
					onChange: (next: boolean) => {
						const nextSelected = next
							? [...selected, c.id]
							: selected.filter((id) => id !== c.id);
						set(key, nextSelected);
					},
				}),
			);
			return api.element.createElement(
				'div',
				{ key },
				api.element.createElement('p', null, field.label),
				...boxes,
				field.help !== undefined
					? api.element.createElement('p', { className: 'components-base-control__help' }, field.help)
					: null,
			);
		}
		case 'tags': {
			/* Checkbox list, same as categories but for post tags. */
			if (tags === null) {
				return api.element.createElement(api.components.Spinner, { key });
			}
			const selected: number[] = Array.isArray(value)
				? value.filter((item): item is number => typeof item === 'number')
				: [];
			const boxes = tags.map((t) =>
				api.element.createElement(api.components.CheckboxControl, {
					key: t.id,
					label: t.name,
					checked: selected.includes(t.id),
					onChange: (next: boolean) => {
						const nextSelected = next
							? [...selected, t.id]
							: selected.filter((id) => id !== t.id);
						set(key, nextSelected);
					},
				}),
			);
			return api.element.createElement(
				'div',
				{ key },
				api.element.createElement('p', null, field.label),
				...boxes,
				field.help !== undefined
					? api.element.createElement('p', { className: 'components-base-control__help' }, field.help)
					: null,
			);
		}
		case 'locations': {
			/* The weather locations list: saved places with remove
			 * buttons, plus a geocoding search to add more. A real
			 * component — it holds the search state. */
			return api.element.createElement(LocationsField, { key, field, value, set, api });
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
		const categories = api.data.useSelect(
			(select) =>
				select('core').getEntityRecords('taxonomy', 'category', { per_page: 100 }),
			[],
		);
		const tags = api.data.useSelect(
			(select) =>
				select('core').getEntityRecords('taxonomy', 'post_tag', { per_page: 100 }),
			[],
		);
		const set = (key: string, next: unknown): void => {
			setAttributes({ [key]: next });
		};
		const controls = def.fields.map((field) =>
			renderField(field, attributes, set, categories, tags, api),
		);
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
		 * will show — not a title/description placeholder. */
		const preview = api.element.createElement(
			'div',
			blockProps,
			api.serverSideRender !== undefined
				? api.element.createElement(api.serverSideRender.ServerSideRender, {
						block: `koperasi-kita/${def.slug}`,
						attributes,
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
	};
	const headerStyleField: FieldDef = {
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
	const storyFields: FieldDef[] = [
		{ key: 'title', kind: 'text', label: __('Title') },
		headerStyleField,
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

	return [
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
			description: __('Numbered list of posts from the categories and tags you pick, newest first.'),
			icon: 'star-filled',
			attributes: {
				...storyAttributes,
				categoryIds: { type: 'array', default: [], items: { type: 'number' } },
				tagIds: { type: 'array', default: [], items: { type: 'number' } },
				showNumeric: { type: 'boolean', default: true },
				showDate: { type: 'boolean', default: true },
				showCategories: { type: 'boolean', default: false },
			},
			fields: [
				...storyFields,
				{
					key: 'categoryIds',
					kind: 'categories',
					label: __('Categories'),
					help: __('Leave all unchecked to show posts from every category.'),
				},
				{
					key: 'tagIds',
					kind: 'tags',
					label: __('Tags'),
					help: __('Leave all unchecked to show posts from every tag.'),
				},
				{ key: 'showNumeric', kind: 'toggle', label: __('Show numeric') },
				{ key: 'showDate', kind: 'toggle', label: __('Show date') },
				{ key: 'showCategories', kind: 'toggle', label: __('Show categories') },
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
				{
					key: 'termId',
					kind: 'select',
					label: __('Category'),
					options: [{ value: '', label: __('— Select —') }, ...(kkWidgetBlocks?.categories ?? [])],
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
				{
					key: 'termId',
					kind: 'select',
					label: __('Tag'),
					options: [{ value: '', label: __('— Select —') }, ...(kkWidgetBlocks?.tags ?? [])],
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
				{
					key: 'categoryIds',
					kind: 'multiselect',
					label: __('Categories to show'),
					help: __('Leave empty to show the three categories with the most posts.'),
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
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				{
					key: 'brands',
					kind: 'multiselect',
					label: __('Brands to show'),
					help: __('Leave empty to show all brands.'),
					options: kkWidgetBlocks?.goldBrands ?? [],
				},
			],
		},
		{
			slug: 'gold-data-desk',
			title: __('KK Gold Data Desk'),
			description: __('Gold prices for Indonesian gold brands as a data table. Pick a template.'),
			icon: 'editor-table',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				template: { type: 'string', default: '' },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title'), help: __('Leave empty to use the template title.') },
				headerStyleField,
				{
					key: 'template',
					kind: 'select',
					label: __('Template'),
					options: kkWidgetBlocks?.dataDeskTableTemplates ?? [],
				},
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
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
				{
					key: 'template',
					kind: 'select',
					label: __('Template'),
					options: kkWidgetBlocks?.dataDeskTemplates ?? [],
				},
			],
		},
		{
			slug: 'markets',
			title: __('KK Markets'),
			description: __('Gold, stock and crypto prices from the Markets providers.'),
			icon: 'chart-line',
			attributes: {
				title: { type: 'string', default: '' },
				headerStyle: { type: 'string', default: 'classic-top' },
				markets: { type: 'array', default: [], items: { type: 'string' } },
				showChange: { type: 'boolean', default: true },
				layout: { type: 'string', default: 'list' },
				visible: { type: 'number', default: 1 },
				style: { type: 'string', default: 'default' },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				headerStyleField,
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
			description: __('Upcoming cooperative events as a dark card; live events get a green dot, finished events are dimmed.'),
			icon: 'calendar',
			attributes: {
				title: { type: 'string', default: __('Agenda Koperasi') },
				count: { type: 'number', default: 5 },
				showPast: { type: 'boolean', default: true },
			},
			fields: [
				{ key: 'title', kind: 'text', label: __('Title') },
				{ key: 'count', kind: 'number', label: __('Number of items'), min: 1, max: 10 },
				{
					key: 'showPast',
					kind: 'toggle',
					label: __('Show past events'),
				},
			],
		},
	];
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
		 * defaults, so the preview is the block as first inserted —
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
