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

    /**
     * Regras do e-mail dos métodos online (o `payer.email` do gateway). `filter` recusa o que o
     * `filter_var` recusa (ex.: espaço no meio, que a validação RFC sozinha aceita).
     */
    public const EMAIL_RULES = ['email:rfc,filter', 'max:180'];

    public function isMercadoPago(): bool
    {
        return in_array($this, [
            self::MP_PIX,
            self::MP_CREDIT_CARD,
            self::MP_DEBIT_CARD,
        ], true);
    }

    /** Pix cobrado por gateway (gera QR/copia e cola), não a "chave Pix" manual. */
    public function isOnlinePix(): bool
    {
        return $this === self::MP_PIX;
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

    /** Texto do botão único de ação do checkout para este método (pagamento online). */
    public function payActionLabel(): string
    {
        return match ($this) {
            self::MP_PIX => 'Pagar com PIX',
            self::MP_CREDIT_CARD => 'Pagar com cartão de crédito',
            self::MP_DEBIT_CARD => 'Pagar com cartão de débito',
            default => 'Enviar pedido',
        };
    }

    /** Mensagem de e-mail obrigatório/inválido, coerente com o que o cliente vai receber. */
    public function emailRequirementMessage(): string
    {
        return $this === self::MP_PIX
            ? 'Informe um e-mail válido para receber o comprovante do Pix.'
            : 'Informe um e-mail válido para receber o comprovante do pagamento.';
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
