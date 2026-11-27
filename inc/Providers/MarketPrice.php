<?php

declare(strict_types=1);

namespace KoperasiKita\Providers;

/**
 * A single market price snapshot.
 *
 * Prices are plain data: a value in the given currency, the day change in
 * percent, and the Unix timestamp the snapshot was saved. A null price means
 * "no data" — renderers must treat that as an honest empty state, never as
 * zero and never as a made-up number. `provider` names the provider that
 * actually supplied this price ('' when unknown, e.g. a legacy snapshot);
 * the Gold Prices admin page and the widget show it so the admin can see
 * whether the primary or the fallback provider delivered the value.
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
        public readonly int $updated,
        public readonly string $provider = ''
    ) {
    }

    public function hasData(): bool
    {
        return $this->price !== null;
    }

    /** A copy of this price annotated with the supplying provider id. */
    public function withProvider(string $provider): self
    {
        return new self(
            $this->market,
            $this->symbol,
            $this->label,
            $this->price,
            $this->change,
            $this->currency,
            $this->updated,
            $provider
        );
    }

    /** A copy of this price with the day change in percent attached. */
    public function withChange(?float $change): self
    {
        return new self(
            $this->market,
            $this->symbol,
            $this->label,
            $this->price,
            $change,
            $this->currency,
            $this->updated,
            $this->provider
        );
    }

    /**
     * @return array{market: string, symbol: string, label: string, price: float|null, change: float|null, currency: string, updated: int, provider: string}
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
            'provider' => $this->provider,
        ];
    }
}
