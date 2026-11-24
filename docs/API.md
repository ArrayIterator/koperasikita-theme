# Koperasi Kita — API

Version **1** (`KK_API_VERSION`, `KoperasiKita\Api\Api::VERSION`). The REST
namespace is `koperasikita/v1`. The theme is pre-1.0: internal PHP APIs may
still move between releases; the version above is bumped whenever the
public surface changes in a way that breaks existing callers.

This document describes the public surface only: the JSON envelope, the
AJAX endpoints, the REST routes, the hooks and filters, the provider
interfaces and the JavaScript client. Everything here is verified against
the code in this repository.

## The one envelope

Every AJAX endpoint and every REST route answers with the same shape:

```json
// Success
{ "success": true, "data": { "...": "..." }, "error": null }

// Failure
{ "success": false, "data": null, "error": { "code": "kk_not_found", "message": "Markets are disabled." } }
```

The HTTP status travels separately (the `wp_send_json` / `WP_REST_Response`
argument) and is never smuggled inside the envelope. The `error.code` is
stable and machine-readable — branch on the code, never on the message
text. The `error.message` is human-readable and safe to display.

### Error codes

| Code | HTTP | Meaning |
| --- | --- | --- |
| `kk_missing_nonce` | 403 | The request carried no nonce. |
| `kk_invalid_nonce` | 403 | The nonce did not verify. |
| `kk_forbidden` | 403 | The user lacks the required capability. |
| `kk_not_found` | 404 | Unknown route / market / feature is disabled. |
| `kk_invalid_request` | 400 | A parameter is missing, malformed or out of range. |
| `kk_upstream_failed` | 502 / 503 | A provider (Open-Meteo, Google Fonts) could not be reached or answered badly. |
| `kk_download_failed` | 500 | A font file was invalid or could not be written. |
| `kk_pending` | 409 | A font download is already in progress. |
| `kk_rate_limited` | 429 | Too many requests (reserved; form handlers rate-limit separately). |
| `kk_internal_error` | 500 | An unexpected error; the message is generic on purpose. |

## AJAX endpoints

All endpoints live on `admin-ajax.php` (`action=kk_*`) and share one
contract, enforced by `KoperasiKita\Api\Ajax::add($action, $handler, $nopriv, $capability)`:

- The nonce is verified by the dispatcher (`koperasikita_nonce`, sent as
  the `nonce` POST field) — handlers never check it themselves. Missing
  or invalid nonce → `kk_missing_nonce` / `kk_invalid_nonce` (403).
- The capability, when given, is checked by the dispatcher — handlers
  never check it themselves. Failing it → `kk_forbidden` (403).
- A handler returns a data array, or throws `KoperasiKita\Api\ApiException`
  with a code and an HTTP status. Any other throwable becomes
  `kk_internal_error` (500) with a generic message; internals never leak.
- A request that is not POST, or whose action is not registered, never
  reaches a handler.

| Action | nopriv | Capability | Parameters | Returns (`data`) |
| --- | --- | --- | --- | --- |
| `kk_weather` | yes | — | `name`, `latitude`, `longitude` | `{ weather: { name, latitude, longitude, temperature, feels_like, humidity, wind_speed, wind_direction, high, low, code, condition } }` |
| `kk_weather_search` | yes | — | `query` (min. 2 chars) | `{ results: [{ name, latitude, longitude, country }] }` (max 5) |
| `kk_download_font` | no | `edit_theme_options` | `font` (font id) | `{ cssUrl, status: "ready" }` |
| `kk_clear_fonts` | no | `edit_theme_options` | — | `{ status: "cleared" }` |

`kk_weather` with no coordinates returns the default location (Padang).
Upstream failure → `kk_upstream_failed` (503). Unknown city / bad
coordinates → `kk_invalid_request` (400). A font download already in
progress → `kk_pending` (409). The weather endpoints are public because
the data they return is already shown to anonymous visitors on the site.

## REST API — `koperasikita/v1`

Read-only. Every route has an explicit `permission_callback`. The public
routes are public by design: they expose only data already visible to
anonymous visitors on the front page. The fonts route is admin-only.

| Route | Access | Returns (`data`) |
| --- | --- | --- |
| `GET /markets` | public | `{ "crypto": { "btc": MarketPrice, … }, "gold": { "spot": MarketPrice, … }, "stocks": { … } }` — prices are keyed by symbol |
| `GET /markets/{market}` | public | `{ market: "crypto", prices: { "btc": MarketPrice, … } }` — `market` is `crypto`, `gold` or `stocks` |
| `GET /gold` | public | `{ market: "gold", prices: { "spot": MarketPrice, "<brand>": MarketPrice, … } }` — the per-gram spot price plus each brand with a saved snapshot |
| `GET /weather` | public | The weather object (same shape as `kk_weather`), for `?lat=&lon=`, `?city=`, or the default location when neither is given |
| `GET /fonts` | `edit_theme_options` | `{ fonts: { "<id>": { label, type, role, status, cssUrl } } }` |

A disabled feature is a 404 (`kk_not_found`); an unknown market is a
404; an unknown city, only one of `lat`/`lon`, or out-of-range
coordinates is a 400 (`kk_invalid_request`); an upstream failure is a
503 (`kk_upstream_failed`). An empty result is an empty object — never
fake data.

A `MarketPrice` is `{ market, symbol, label, price, change, currency, updated }`:
`label` is the display name, `price` is the value in `currency`
(`change` is the 24h change in percent, `null` when unknown) and
`updated` is the Unix timestamp the snapshot was saved. A symbol with
no saved snapshot is left out entirely.

## Hooks and filters

One prefix everywhere: `koperasikita/`. The constants live in
`KoperasiKita\Api\Hooks`; the `Markets::PROVIDERS_FILTER` constant is an
alias kept for backwards compatibility. Every filtered value is
validated defensively after filtering — malformed entries are dropped,
never trusted.

| Hook | Type | Value |
| --- | --- | --- |
| `koperasikita/markets/providers` | filter | `array<string, MarketPriceProvider>` — market slug ⇒ provider object. A provider must implement `MarketPriceProvider`; anything else is dropped. |
| `koperasikita/markets/labels` | filter | `array<string, string>` — market slug ⇒ display label (e.g. `crypto` ⇒ `Crypto`). |
| `koperasikita/markets/symbols` | filter | `array<string, string>` — slug ⇒ label for ONE market. The filter receives that market's symbols array, plus the market slug as the second argument. Entries without a string slug and label are dropped. |
| `koperasikita/weather/providers` | filter | `array<string, WeatherProvider>` — provider id ⇒ provider object. A provider must implement `WeatherProvider`; anything else is dropped. |
| `koperasikita/weather/quick_cities` | filter | `array<int, array{name: string, lat: float, lon: float}>` — the quick-pick cities in the weather picker. Entries without a name and in-range coordinates are dropped. |
| `koperasikita/gold/brands` | filter | `array<string, string>` — brand slug ⇒ display name. A brand added here with no saved snapshot still has no price. |
| `koperasikita/fonts/catalogue` | filter | The full font catalogue (`role` ⇒ `id` ⇒ entry). Entries must carry string `id`, `label`, `type` and `stack`; Google entries must also carry a `family` string and a non-empty `weights` array; `type` must be `google` or `system`. |

## Provider interfaces

One shape for all data providers: `id()`, `label()`, `supports()`,
a fetch method, and honest empty results (no data ⇒ empty array or
`null`, never fabricated values). A provider name is never taken from
client input — providers come only from the allowlisted filter above.

### `KoperasiKita\Providers\MarketPriceProvider`

```php
interface MarketPriceProvider
{
    public function id(): string;        // e.g. 'manual'
    public function label(): string;     // e.g. 'Manual snapshots'
    public function supports(string $market): bool;
    /** @return MarketPrice[] */
    public function prices(string $market): array;
}
```

`MarketPrice` is the value object (`symbol`, `name`, `price`, `change`,
`currency`, `source`, plus `toArray()`). The built-in provider is
`ManualSnapshotProvider`: prices entered by hand in the Markets settings
page. `Markets::providerFor($market)` picks the first registered provider
that supports the market; when none does, `prices()` returns `[]`.

### `KoperasiKita\Providers\WeatherProvider`

```php
interface WeatherProvider
{
    public function id(): string;        // e.g. 'open-meteo'
    public function label(): string;     // e.g. 'Open-Meteo'
    /** @param array{name: string, latitude: float, longitude: float} $location
      * @return array{name: string, latitude: float, longitude: float, temperature: float, feels_like: float|null, humidity: int|null, wind_speed: float|null, wind_direction: int|null, high: float|null, low: float|null, code: int, condition: string}|null — null when the upstream fails */
    public function current(array $location): ?array;
    /** @return array<int, array{name: string, latitude: float, longitude: float, country: string}> */
    public function search(string $query): array;
}
```

The built-in provider is `OpenMeteoProvider` (keyless Open-Meteo,
cached in a transient). `Weather::current()` / `Weather::search()`
delegate to the first registered provider; with no provider they return
`null` / `[]`. The WMO weather-code labels live in the provider, not in
the `Weather` façade.

### Fonts

Fonts have no provider interface — the catalogue is data, not a
provider. `Fonts::catalogue()` is the single source of truth (only
listed fonts can be selected, downloaded or enqueued); extend it through
the `koperasikita/fonts/catalogue` filter. The download engine is
`FontDownloader` (fetch the css2 stylesheet, parse it, download the
woff2 files, write them atomically); `Fonts` owns the catalogue, the
download state and the Customizer wiring.

## JavaScript API

One shared client: `src/ts/api.ts`. Every AJAX call in the theme goes
through `postAjax(config, action, params?, signal?)`, which resolves
with the `data` payload and rejects with an `Error` carrying the
server's `error.message`. The three previous per-file copies
(`weather.ts`, `admin.ts`, `customizer-preview.ts`) are gone.

Localized data, all declared in `src/ts/main.ts` as `KkData`:

```ts
interface KkData {
  ajaxUrl?: string;            // admin-ajax.php
  nonce?: string;              // koperasikita_nonce
  themeUrl?: string;           // theme directory URL
  isBookmarksEnabled?: boolean;
  isPwaEnabled?: boolean;
  isTickerEnabled?: boolean;
  isWeatherEnabled?: boolean;
  isMarketsEnabled?: boolean;
  bookmarksPageUrl?: string;
}
```

`kkAdminData` (`{ ajaxUrl, nonce }`) is the admin equivalent for the
Customizer font management; `kkFontsPreview` (`{ ajaxUrl, nonce, fonts }`)
carries the font catalogue for the Customizer preview, where each font
entry is `{ stack, cssUrl, google }`.

## Separation of concerns

| Area | Classes |
| --- | --- |
| API | `Api\Api` (version, namespace, registration), `Api\Response` (envelope), `Api\ApiException`, `Api\Ajax` (dispatcher), `Api\Rest` (routes), `Api\Hooks` (hook constants) |
| Providers | `Providers\MarketPrice`, `Providers\MarketPriceProvider`, `Providers\ManualSnapshotProvider`, `Providers\WeatherProvider`, `Providers\OpenMeteoProvider` |
| Features | `Features\Markets` (catalogue, settings, prices, formatting), `Features\Weather` (façade over the weather providers), `Features\Gold` (brand registry), `Features\Fonts` (catalogue, state, enqueue, Customizer), `Features\FontDownloader` (download engine) |
| Admin | `Admin\MarketsSettings` (the Markets settings page) |

## Breaking changes (pre-1.0)

- The AJAX envelope changed from WordPress's `{success, data}` /
  `{success: false, data: {message}}` to the unified
  `{success, data, error: {code, message}}`; error text moved from
  `data.message` to `error.message`. All three TypeScript call sites
  were updated to the new shape.
- `MarketPrice`, `MarketPriceProvider` and `ManualSnapshotProvider`
  moved from `KoperasiKita\Features` (Markets.php) to
  `KoperasiKita\Providers`.
- The Markets settings-page methods moved from `Markets` to
  `KoperasiKita\Admin\MarketsSettings` (`registerSettingsPage`,
  `registerSettings`, `sanitizeSettings`, `renderSettingsPage`).
- The weather fetch/cache internals and the WMO label map moved from
  `Weather` to `OpenMeteoProvider`. The public `Weather::current()`,
  `Weather::search()`, `Weather::resolveCity()`, `Weather::codeLabel()`,
  `Weather::quickCities()` and `Weather::defaultLocation()` are unchanged.
  Two methods were added: `Weather::isEnabled()` (mirrors
  `Markets::isEnabled()`) and `Weather::findCity()` — strict city
  resolution that returns `null` when a city cannot be found, where
  `resolveCity()` falls back to the default city. `resolveCity()` now
  delegates to `findCity()`.
- The font download engine moved from `Fonts` to `FontDownloader`
  (`parseCss2`, `buildLocalCss`, `downloadFaces`, `writeFileAtomically`,
  `isFontFileUrl`, `deleteFontFiles`). `Fonts::NONCE_ACTION` is gone —
  the nonce action is `Api\Ajax::NONCE_ACTION`.
- `Fonts::ensureFont()` now returns a `code` and `http_status` on
  failure instead of a free-text `error` alone; the pending case
  reports `kk_pending` (409) with the message
  "Download already in progress."

## Security notes

- Mutating AJAX endpoints require `edit_theme_options` plus the nonce;
  the dispatcher enforces both before any handler runs.
- Every REST route has an explicit `permission_callback`; the public
  ones return data that is already public on the front page.
- Every input is validated and sanitised: coordinates are range-checked,
  city names are resolved against an allowlist, font ids are re-validated
  against `^[a-z0-9-]+$`, provider objects come only from the filtered
  registry (never from client input), and font file URLs must be
  `https://fonts.gstatic.com/*.woff2`.
- Font files are written atomically (temporary file + rename) and only
  inside the fonts upload directory; clearing fonts refuses any path
  outside it.
- Error messages never expose paths, keys or internals; unexpected
  failures answer with a generic `kk_internal_error`.
