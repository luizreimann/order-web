<?php

namespace Tests\Unit\Enums;

use App\Enums\GatewayPaymentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * E2.6 — contrato dos enums de status: valores (== CHECK do banco), rótulos pt-BR e
 * cor Bootstrap conforme D8.
 */
class StatusEnumsTest extends TestCase
{
    public function test_order_status_cases_and_values(): void
    {
        $this->assertSame(
            ['pending', 'confirmed', 'done', 'cancelled'],
            array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases())
        );
        $this->assertSame(['pending', 'confirmed', 'done', 'cancelled'], OrderStatus::values());
    }

    public function test_payment_status_cases_and_values(): void
    {
        $this->assertSame(
            ['unpaid', 'paid', 'refunded'],
            array_map(fn (PaymentStatus $s) => $s->value, PaymentStatus::cases())
        );
        $this->assertSame(['unpaid', 'paid', 'refunded'], PaymentStatus::values());
    }

    public function test_order_status_variants_follow_d8(): void
    {
        $this->assertSame('warning', OrderStatus::PENDING->variant());
        $this->assertSame('info', OrderStatus::CONFIRMED->variant());
        $this->assertSame('success', OrderStatus::DONE->variant());
        $this->assertSame('secondary', OrderStatus::CANCELLED->variant());
    }

    public function test_payment_status_variants_follow_d8(): void
    {
        $this->assertSame('warning', PaymentStatus::UNPAID->variant());
        $this->assertSame('success', PaymentStatus::PAID->variant());
        $this->assertSame('secondary', PaymentStatus::REFUNDED->variant());
    }

    public function test_labels_are_portuguese(): void
    {
        $this->assertSame('Pendente', OrderStatus::PENDING->label());
        $this->assertSame('Confirmado', OrderStatus::CONFIRMED->label());
        $this->assertSame('Concluído', OrderStatus::DONE->label());
        $this->assertSame('Cancelado', OrderStatus::CANCELLED->label());
        $this->assertSame('Não pago', PaymentStatus::UNPAID->label());
        $this->assertSame('Pago', PaymentStatus::PAID->label());
        $this->assertSame('Reembolsado', PaymentStatus::REFUNDED->label());
    }

    /**
     * D8: `primary` é sobrescrito pelo tema do tenant e `danger` é reservado a ações
     * destrutivas — nenhum status pode usá-los.
     */
    #[DataProvider('allStatusCases')]
    public function test_no_status_uses_primary_or_danger(OrderStatus|PaymentStatus|GatewayPaymentStatus $case): void
    {
        $this->assertNotContains($case->variant(), ['primary', 'danger'], $case::class.'::'.$case->name);
        $this->assertContains($case->variant(), ['secondary', 'success', 'info', 'warning', 'light', 'dark']);
        $this->assertNotSame('', $case->label());
    }

    public static function allStatusCases(): iterable
    {
        foreach ([OrderStatus::class, PaymentStatus::class, GatewayPaymentStatus::class] as $enum) {
            foreach ($enum::cases() as $case) {
                yield class_basename($enum).'::'.$case->name => [$case];
            }
        }
    }

    #[DataProvider('unknownGatewayValues')]
    public function test_gateway_status_ignores_values_it_does_not_know(string $raw): void
    {
        $this->assertNull(GatewayPaymentStatus::tryFrom($raw));
    }

    public static function unknownGatewayValues(): iterable
    {
        yield 'valor novo do MP' => ['algo_novo_do_mp'];
        yield 'vazio' => [''];
        yield 'caixa diferente' => ['APPROVED'];
    }

    public function test_gateway_status_maps_raw_mercado_pago_values(): void
    {
        $this->assertSame(GatewayPaymentStatus::APPROVED, GatewayPaymentStatus::tryFrom('approved'));

        $this->assertSame(PaymentStatus::PAID, GatewayPaymentStatus::APPROVED->toPaymentStatus());
        $this->assertSame(PaymentStatus::UNPAID, GatewayPaymentStatus::PENDING->toPaymentStatus());
        $this->assertSame(PaymentStatus::UNPAID, GatewayPaymentStatus::IN_PROCESS->toPaymentStatus());
        $this->assertSame(PaymentStatus::REFUNDED, GatewayPaymentStatus::REFUNDED->toPaymentStatus());
        $this->assertSame(PaymentStatus::REFUNDED, GatewayPaymentStatus::CHARGED_BACK->toPaymentStatus());
        foreach ([GatewayPaymentStatus::REJECTED, GatewayPaymentStatus::CANCELLED, GatewayPaymentStatus::AUTHORIZED, GatewayPaymentStatus::IN_MEDIATION] as $neutral) {
            $this->assertNotInstanceOf(PaymentStatus::class, $neutral->toPaymentStatus(), $neutral->name);
        }
    }
}
