<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Janela em que o cliente pode pagar o Pix (`services.mercadopago.pix_window`, em segundos).
 *
 * É uma regra NOSSA, imposta pelo servidor, e independe de {@see PixExpiration}: o Mercado Pago
 * não aceita validade menor que 30 minutos em `expiration_time` (esse prazo continua como
 * teto de segurança do lado do MP). Vencida a janela (+ uma pequena tolerância), o servidor
 * cancela a order no MP e só então o pedido local ({@see \App\Services\PixExpiryEnforcer}).
 *
 * Padrão e TETO: 300 s (5 min). Valor acima do teto vira o teto; abaixo do piso (60 s) vira o
 * piso; não numérico, zero ou negativo cai no padrão.
 */
final class PixWindow
{
    public const DEFAULT_SECONDS = 300;

    public const MIN_SECONDS = 60;

    public const MAX_SECONDS = 300;

    /** Tolerância (s) após o prazo antes de cancelar: o cliente paga até o último segundo. */
    public const GRACE_SECONDS = 10;

    /** Chave, em `payments.meta`, do instante absoluto (ISO 8601, UTC) em que a janela fecha. */
    public const META_KEY = 'pix_expires_at';

    public static function seconds(): int
    {
        $configured = config('services.mercadopago.pix_window');

        if (is_string($configured)) {
            $configured = trim($configured);
            $configured = preg_match('/\A-?\d+\z/', $configured) === 1 ? (int) $configured : null;
        } elseif (is_float($configured) && floor($configured) === $configured) {
            $configured = (int) $configured;
        }

        if (! is_int($configured) || $configured <= 0) {
            return self::DEFAULT_SECONDS;
        }

        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $configured));
    }

    /**
     * Instante em que a janela de um Pix aceito AGORA fecha. Arredondado PARA CIMA ao segundo: o
     * prazo é gravado em segundos inteiros e o cliente nunca pode ficar com MENOS que a janela.
     */
    public static function expiresAtFromNow(): CarbonImmutable
    {
        return CarbonImmutable::now()->addSeconds(self::seconds())->ceilSecond()->utc();
    }

    /** Valor gravado em `payments.meta.pix_expires_at`. */
    public static function format(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** @param  array<string, mixed>|null  $meta  `payments.meta` */
    public static function expiresAt(?array $meta): ?CarbonImmutable
    {
        $value = $meta[self::META_KEY] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * O que o front precisa para o contador: o prazo ABSOLUTO e a hora do servidor (para corrigir o
     * relógio do cliente). Vazio se o pagamento não tem prazo gravado. Só dois instantes: nada mais.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{expires_at?: string, server_now?: string}
     */
    public static function timing(?array $meta): array
    {
        $expiresAt = self::expiresAt($meta);

        if ($expiresAt === null) {
            return [];
        }

        return [
            'expires_at' => self::format($expiresAt),
            'server_now' => CarbonImmutable::now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    /**
     * O Pix deste pagamento venceu (janela + tolerância)?
     *
     * Com `pix_expires_at` gravado, vale ele. Sem ele (cobrança aceita mas não consolidada, ou
     * anterior a esta regra), um Pix cobrado por gateway vence `janela` depois de criado: não ter
     * prazo gravado não pode deixar um pedido e o estoque presos para sempre. Cartão e demais
     * métodos NUNCA estão vencidos por esta regra.
     */
    public static function isDueFor(Payment $payment, ?CarbonInterface $now = null): bool
    {
        if (self::expiresAt($payment->meta) !== null) {
            return self::isDue($payment->meta, $now);
        }

        $order = $payment->order;
        $method = $order instanceof Order ? PaymentMethod::tryFrom((string) $order->payment_method) : null;

        if ($method?->isOnlinePix() !== true || $payment->created_at === null) {
            return false;
        }

        $deadline = CarbonImmutable::instance($payment->created_at)->addSeconds(self::seconds() + self::GRACE_SECONDS);

        return ($now ?? CarbonImmutable::now())->greaterThan($deadline);
    }

    /**
     * A janela, mais a tolerância, já passou? Sem `pix_expires_at` nunca está vencido por AQUI
     * (para o fallback por data de criação, {@see isDueFor()}).
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function isDue(?array $meta, ?CarbonInterface $now = null): bool
    {
        $expiresAt = self::expiresAt($meta);

        if ($expiresAt === null) {
            return false;
        }

        $now ??= CarbonImmutable::now();

        return $now->greaterThan($expiresAt->addSeconds(self::GRACE_SECONDS));
    }
}
