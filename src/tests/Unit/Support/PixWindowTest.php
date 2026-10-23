<?php

namespace Tests\Unit\Support;

use App\Support\PixExpiration;
use App\Support\PixWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Janela de pagamento do Pix (`MERCADOPAGO_PIX_WINDOW`): padrão e teto de 300 s, piso de 60 s,
 * inválido cai no padrão, e é INDEPENDENTE do `expiration_time` enviado ao Mercado Pago.
 */
class PixWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_the_default_is_five_minutes(): void
    {
        $this->assertSame(300, PixWindow::seconds());
        $this->assertSame(300, (int) config('services.mercadopago.pix_window'));
    }

    /** @return array<string, array{0: mixed, 1: int}> */
    public static function configuredValues(): array
    {
        return [
            'valor válido' => [120, 120],
            'string numérica (vem do .env)' => ['180', 180],
            'string com espaços' => [' 240 ', 240],
            'no teto' => [300, 300],
            'acima do teto vira o teto' => [600, 300],
            'muito acima do teto' => ['86400', 300],
            'no piso' => [60, 60],
            'abaixo do piso vira o piso' => [10, 60],
            'um segundo vira o piso' => [1, 60],
            'zero cai no padrão' => [0, 300],
            'negativo cai no padrão' => [-30, 300],
            'texto cai no padrão' => ['abc', 300],
            'duração ISO não vale (é segundos)' => ['PT5M', 300],
            'decimal em texto cai no padrão' => ['2.5', 300],
            'vazio cai no padrão' => ['', 300],
            'nulo cai no padrão' => [null, 300],
            'array cai no padrão' => [[1], 300],
        ];
    }

    #[DataProvider('configuredValues')]
    public function test_the_configured_value_is_clamped(mixed $configured, int $expected): void
    {
        config(['services.mercadopago.pix_window' => $configured]);

        $this->assertSame($expected, PixWindow::seconds());
    }

    public function test_the_window_is_independent_of_the_expiration_time_sent_to_mercado_pago(): void
    {
        config(['services.mercadopago.pix_window' => 120, 'services.mercadopago.pix_expiration' => 'PT45M']);

        $this->assertSame(120, PixWindow::seconds());
        $this->assertSame('PT45M', PixExpiration::iso());
        $this->assertGreaterThanOrEqual(PixExpiration::MIN_SECONDS, PixExpiration::seconds());

        // Mesmo com o teto de segurança do MP mal configurado, a janela do cliente não muda.
        config(['services.mercadopago.pix_expiration' => 'PT5M']);
        $this->assertSame('PT30M', PixExpiration::iso());
        $this->assertSame(120, PixWindow::seconds());
    }

    public function test_the_deadline_is_an_absolute_utc_instant_now_plus_the_window(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00');

        $at = PixWindow::expiresAtFromNow();

        $this->assertSame('2026-10-23T12:05:00Z', PixWindow::format($at));
        $this->assertSame(['pix_expires_at' => '2026-10-23T12:05:00Z'], [PixWindow::META_KEY => PixWindow::format($at)]);
        $this->assertTrue(PixWindow::expiresAt([PixWindow::META_KEY => '2026-10-23T12:05:00Z'])->equalTo($at));
    }

    public function test_timing_carries_only_the_deadline_and_the_server_clock(): void
    {
        CarbonImmutable::setTestNow('2026-10-23 12:00:00.250');

        $this->assertSame([], PixWindow::timing(null));
        $this->assertSame([], PixWindow::timing(['point_of_interaction' => ['qr_code' => 'x']]));
        $this->assertSame([], PixWindow::timing([PixWindow::META_KEY => 'não é data']));

        $this->assertSame(
            ['expires_at' => '2026-10-23T12:05:00Z', 'server_now' => '2026-10-23T12:00:00.250Z'],
            PixWindow::timing(['pix_expires_at' => '2026-10-23T12:05:00Z', 'qr_code' => 'x'])
        );
    }

    public function test_due_only_after_the_window_plus_the_grace(): void
    {
        $meta = [PixWindow::META_KEY => '2026-10-23T12:05:00Z'];
        $at = fn (string $time) => CarbonImmutable::parse('2026-10-23 '.$time, 'UTC');

        $this->assertFalse(PixWindow::isDue($meta, $at('12:00:00')));
        $this->assertFalse(PixWindow::isDue($meta, $at('12:04:59')), 'janela − 1 s: o cliente ainda pode pagar');
        $this->assertFalse(PixWindow::isDue($meta, $at('12:05:00')));
        $this->assertFalse(PixWindow::isDue($meta, $at('12:05:10')), 'dentro da tolerância');
        $this->assertTrue(PixWindow::isDue($meta, $at('12:05:11')), 'janela + tolerância + 1 s');
        $this->assertTrue(PixWindow::isDue($meta, $at('13:00:00')));
    }

    public function test_a_payment_without_a_deadline_is_never_due(): void
    {
        $this->assertFalse(PixWindow::isDue(null));
        $this->assertFalse(PixWindow::isDue([]));
        $this->assertFalse(PixWindow::isDue([PixWindow::META_KEY => '']));
        $this->assertFalse(PixWindow::isDue([PixWindow::META_KEY => 'lixo']));
    }
}
