<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

use KoperasiKita\Features\Markets;

/**
 * Live gold provider (scraped, free, self-hosted).
 *
 * Reads scraper/prices.json from the theme's own GitHub repo
 * (raw.githubusercontent.com), published by the GitHub Actions
 * workflow in .github/workflows/gold-prices.yml, which scrapes the
 * prices no free API provides:
 *  - Antam 1-gram retail price, scraped from harga-emas.net.
 *  - Galeri 24 + UBS + Lotus Archi 1-gram sell prices, scraped
 *    from galeri24.co.id/harga-emas (server-rendered brand tables).
 *  - Hartadinata 1-gram buy price, from the official hrtagold.id
 *    API (meta description "Beli: Rp ..." as fallback).
 *  - King Halim 1-gram sell price, scraped from
 *    kinghalim.com/goldbarwithamala (the "1 Gr" card, not the
 *    buyback line).
 *  - Gold spot per gram (IDR), from xaus.com.
 *
 * The JSON is a static file on GitHub's CDN, so it is fast and
 * CORS-enabled — the browser can fetch it directly too (see
 * clientApiUrl()). Picking this provider for gold means Antam +
 * Galeri 24 + UBS + Lotus Archi + Hartadinata + King Halim +
 * spot come live from the scrape.
 *
 * Caching and the stale-value fallback live in AbstractLiveProvider
 * (15-minute transient + last good value in an option).
 */
final class ScrapedGoldProvider extends AbstractLiveProvider
{
    public const PRICES_URL = 'https://raw.githubusercontent.com/ArrayIterator/koperasikita-theme/main/scraper/prices.json';

    public function id(): string
    {
        return 'scraped-gold';
    }

    public function label(): string
    {
        return 'Scraped (live Antam, Galeri 24, UBS, Lotus Archi, Hartadinata, King Halim + spot, free)';
    }

    public function supports(string $market): bool
    {
        return $market === 'gold';
    }

    public function supplies(string $market): array
    {
        if ($market !== 'gold') {
            return [];
        }

        // Scraped from the theme's own prices.json: spot + the six
        // brands the scraper covers.
        $symbols = Markets::symbols('gold');
        $supplied = [];

        foreach (['spot', 'antam', 'ubs', 'galeri-24', 'lotus-archi', 'hartadinata', 'king-halim'] as $symbol) {
            if (isset($symbols[$symbol])) {
                $supplied[$symbol] = $symbols[$symbol];
            }
        }

        return $supplied;
    }

    protected function transientKey(): string
    {
        return 'kk_gold_scraped';
    }

    protected function lastGoodOption(): string
    {
        return 'kk_gold_scraped_last_good';
    }

    public function clientApiUrl(): ?string
    {
        return self::PRICES_URL;
    }

    /**
     * @return array<string, array{price: float, change: float|null, updated: int}>|null
     */
    protected function fetch(string $market): ?array
    {
        $data = $this->getJson(self::PRICES_URL);

        if ($data === null) {
            return null;
        }

        $rows = $data['prices'] ?? null;

        if (!is_array($rows)) {
            return null;
        }

        $prices = [];

        foreach (['spot', 'antam', 'ubs', 'galeri-24', 'lotus-archi', 'hartadinata', 'king-halim'] as $symbol) {
            $row = $rows[$symbol] ?? null;

            if (!is_array($row)) {
                continue;
            }

            $price = $row['price'] ?? null;

            if (!is_numeric($price) || (float) $price <= 0) {
                continue;
            }

            $updated = isset($row['updated']) ? strtotime((string) $row['updated']) : false;

            $prices[$symbol] = [
                'price' => (float) $price,
                'change' => null,
                'updated' => $updated !== false ? (int) $updated : time(),
            ];
        }

        return $prices !== [] ? $prices : null;
    }
}
