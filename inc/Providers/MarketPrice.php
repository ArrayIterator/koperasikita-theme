<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * A single market price snapshot.
 *
 * Prices are plain data: a value in the given currency, the day change in
 * percent, and the Unix timestamp the snapshot was saved. A null price means
 * "no data" — renderers must treat that as an honest empty state, never as
 * zero and never as a made-up number.
 */
final class MarketPrice
{
    public function __construct(
        public readonly string $market,
        public readonly string $symbol,
        public readonly string $label,
        public readonly ?float $price,
        public readonly ?float $change,
        public readonly string $currency,
        public readonly int $updated
    ) {
    }

    public function hasData(): bool
    {
        return $this->price !== null;
    }

    /**
     * @return array{market: string, symbol: string, label: string, price: float|null, change: float|null, currency: string, updated: int}
     */
    public function toArray(): array
    {
        return [
            'market' => $this->market,
            'symbol' => $this->symbol,
            'label' => $this->label,
            'price' => $this->price,
            'change' => $this->change,
            'currency' => $this->currency,
            'updated' => $this->updated,
        ];
    }
}
