<?php

namespace App\Support;

use App\Enums\FulfillmentType;

/**
 * O que custa receber este pedido: tipo, taxa (em centavos), de onde veio a taxa e o pedido
 * mínimo. Imutável; o servidor sempre refaz a conta ao gravar o pedido.
 */
final class FulfillmentQuote
{
    /**
     * @param  'pickup'|'region'|'default'|'free'|'none'  $feeKind
     */
    public function __construct(
        public readonly FulfillmentType $type,
        public readonly int $feeCents,
        public readonly string $feeKind,
        public readonly ?string $regionId,
        public readonly ?string $regionName,
        public readonly int $minimumCents,
        public readonly int $shortfallCents,
        public readonly ?int $estimatedMinutes,
    ) {}

    public function meetsMinimum(): bool
    {
        return $this->shortfallCents === 0;
    }

    public function totalCents(int $subtotalCents): int
    {
        return $subtotalCents + $this->feeCents;
    }

    /** Snapshot gravado em `orders.meta.fulfillment`: a loja pode mudar as regras depois. */
    public function snapshot(): array
    {
        return [
            'type' => $this->type->value,
            'fee_cents' => $this->feeCents,
            'fee_kind' => $this->feeKind,
            'region_id' => $this->regionId,
            'region_name' => $this->regionName,
            'minimum_cents' => $this->minimumCents,
            'estimated_minutes' => $this->estimatedMinutes,
        ];
    }
}
