<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case CASH_ON_DELIVERY = 'cash_on_delivery';
    case CARD_ON_DELIVERY = 'card_on_delivery';
    case PIX_MANUAL = 'pix_manual';
    case MP_PIX = 'mp_pix';
    case MP_CREDIT_CARD = 'mp_credit_card';
    case MP_DEBIT_CARD = 'mp_debit_card';

    public function isMercadoPago(): bool
    {
        return in_array($this, [
            self::MP_PIX,
            self::MP_CREDIT_CARD,
            self::MP_DEBIT_CARD,
        ], true);
    }

    public function isManual(): bool
    {
        return in_array($this, [
            self::CASH_ON_DELIVERY,
            self::CARD_ON_DELIVERY,
            self::PIX_MANUAL,
        ], true);
    }

    public function provider(): string
    {
        return $this->isMercadoPago() ? 'mercadopago' : 'manual';
    }

    public function label(): string
    {
        return match ($this) {
            self::CASH_ON_DELIVERY => 'Dinheiro (na entrega)',
            self::CARD_ON_DELIVERY => 'Cartão (na entrega)',
            self::PIX_MANUAL => 'PIX (Chave)',
            self::MP_PIX => 'PIX (Mercado Pago)',
            self::MP_CREDIT_CARD => 'Cartão de crédito (Mercado Pago)',
            self::MP_DEBIT_CARD => 'Cartão de débito (Mercado Pago)',
        };
    }

    public static function allIds(): array
    {
        return array_map(fn (self $m) => $m->value, self::cases());
    }

    public static function manualIds(): array
    {
        return array_map(fn (self $m) => $m->value, array_filter(self::cases(), fn (self $m) => $m->isManual()));
    }

    public static function mercadoPagoIds(): array
    {
        return array_map(fn (self $m) => $m->value, array_filter(self::cases(), fn (self $m) => $m->isMercadoPago()));
    }

    public static function defaultConfig(): array
    {
        return array_map(fn (self $m) => [
            'id' => $m->value,
            'provider' => $m->provider(),
            'label' => $m->label(),
            'enabled' => $m->isManual(),
            'instructions' => null,
            'config' => $m === self::PIX_MANUAL ? ['pix_key' => null] : [],
        ], self::cases());
    }

    public static function tryFromId(string $id): ?self
    {
        return self::tryFrom($id);
    }

    public static function isMercadoPagoId(string $id): bool
    {
        $method = self::tryFrom($id);
        return $method?->isMercadoPago() ?? false;
    }
}
